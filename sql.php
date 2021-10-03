<?php

class SqlStatement
{
	protected $marie;				// a mysqli object
	protected $query;				// the text of the SQL statement
	protected $paramTypes;			// binding types string -- length equals number of ? placeholders in query
	protected $statement;			// a mysqli_stmt object, after prep() is called
	public static $log = '';		// XXX THIS IS SINGLE THREADED!

	public function __construct(&$marie, $paramType, $query)
	{
		$this->marie = $marie;
		$this->paramType = $paramType;
		$this->query = $query;
	}

	// call this first in any derived class method that executes the statement
	protected function prepAndBind(&...$params)
	{
		// lazy evaluation -- we only prepare the statement if it gets used
			self::$log .= "-- About to prepare $this->query\n";
		if (!$this->statement)
			$this->statement = $this->marie->prepare($this->query);
		else
			self::$log .= "-- Already prepared, about to bind params $this->paramType\n";
		if ($this->paramType && count($params))
			$this->statement->bind_param($this->paramType, ...$params);
		else
			self::$log .= "-- No parameters to bind\n";
	}
}


class Selector extends SqlStatement
{
	private $rowsGotten = 0;
	private $exhausted = false;

	public function select(...$params)		// call this, then call getRow one or more times
	{
		$this->exhausted = false;
		$this->prepAndBind(...$params);
		parent::$log .= "-- About to execute select\n";
		$result = $this->statement->execute();
		$this->statement->store_result();
		$rose = $this->statement->num_rows;
		parent::$log .= "-- We have $rose rows\n";
		return $result;
	}

	public function getRow(&...$results)	// call only after select() succeeds
	{
		if ($this->exhausted)
			return null;
		$this->statement->bind_result(...$results);
		parent::$log .= "-- About to fetch row\n";
		$result = $this->statement->fetch();
		parent::$log .= "-- Fetch returned $result\n";
		if ($result)
			$this->rowsGotten++;
		if ($this->rowsGotten >= $this->statement->num_rows) {
			parent::$log .= "-- All $this->rowsGotten rows fetched, freeing\n";
			$this->statement->free_result();
			$this->exhausted = true;
		}
		return $result;
	}
}

class ScalarSelector extends SqlStatement
{
	public function select(...$params)
	{
		$result = '';
		$this->prepAndBind(...$params);
		$this->statement->bind_result($result);
		parent::$log .= "-- About to execute and fetch scalar query\n";
		$this->statement->execute();
		$this->statement->store_result();
		if ($this->statement->num_rows > 1)
			throw new Exception("ScalarSelector retrieved $this->statement->num_rows rows from query $this->query");
		if ($this->statement->fetch())
			parent::$log .= "-- Fetch successful\n";
		else
			parent::$log .= "-- Fetch FAILED\n";
		$this->statement->free_result();
		return $result;
	}
}

class Inserter extends SqlStatement
{
	public function insert(...$arguments)
	{
		$this->prepAndBind(...$arguments);
		parent::$log .= "-- About to execute insert\n";
		$this->statement->execute();
		return $this->statement->insert_id;
	}
}

class Updater extends SqlStatement		// also use this for delete statements
{
	public function update(...$arguments)
	{
		$this->prepAndBind(...$arguments);
		parent::$log .= "-- About to execute update\n";
		return $this->statement->execute();
	}
}

// In the classes with one method, it would have been cool to name it __invoke, but that didn't work.

?>