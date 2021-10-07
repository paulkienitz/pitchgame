<?php

// This is a simple PHP framework for encapsulating mysqli prepared statements so that they
// can be executed with one simple method, or two in the case of nonscalar select statements.
// It includes the capability to log each action to a common string buffer for error diagnosis.
// By Paul Kienitz, 2021, no license, no rights reserved.

function byn($b) 	// format something you normally expect to be boolean
{
	if (is_null($b))
		return 'null';
	else if (is_string($b))
		return "\"$b\"";
	else
		return $b ? 'true' : 'false';
}

class SqlStatement
{
	protected $marie;				// a mysqli object
	protected $query;				// the text of the SQL statement to prepare
	protected $paramTypes;			// binding types string -- length must equal number of "?" placeholders in query
	protected $statement;			// our mysqli_stmt object, after prepAndBind() is called
	private $logtext;				// a reference to a string variable used for logging output

	public function __construct(&$marie, &$log, $paramType, $query)
	{
		$this->marie = $marie;
		$this->paramType = $paramType;
		$this->query = $query;
		$this->logtext = &$log;
		// Initialize the passed $log variable to null to silence logging, or to '' to activate logging.
		// Typically all SqlStatement objects on the same connection will share a single log string.
	}

	// call this first in any derived class method that executes the statement
	protected function prepAndBind(&...$params)
	{
		// lazy evaluation -- we only prepare the statement if it gets used
		$this->log("$this->query:\n-- already prepared? " . byn(!!$this->statement));
		if (!$this->statement)
			$this->statement = $this->marie->prepare($this->query);
		$this->log(', ' . count($params) . " params '$this->paramType', ");
		if ($this->paramType && count($params))
			$this->statement->bind_param($this->paramType, ...$params);
	}

	protected function log($msg)
	{
		if (is_string($this->logtext))
			$this->logtext .= $msg;
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
		$this->log("executing select");
		$result = $this->statement->execute();
		$this->log(' yielded ' . byn($result));
		$this->statement->store_result();
		$this->log(" and found {$this->statement->num_rows} rows.\n");
		return $result;
	}

	public function getRow(&...$results)	// call only after select() succeeds; one result var must be passed for each column
	{
		if ($this->exhausted)
			return null;
		$this->statement->bind_result(...$results);
		$this->log("Fetching row $this->rowsGotten");
		$result = $this->statement->fetch();
		$this->log(' yielded ' . byn($result) . '. ');
		if ($result)
			$this->rowsGotten++;
		if ($this->rowsGotten >= $this->statement->num_rows) {
			$this->log("\nAll $this->rowsGotten rows fetched, freeing.\n");
			$this->statement->free_result();
			$this->exhausted = true;
		}
		return $result;
	}
}

class ScalarSelector extends SqlStatement
{
	public function select(...$params)			// statement must return only one row containing only one field
	{
		$result = '';
		$this->prepAndBind(...$params);
		$this->statement->bind_result($result);
		$this->log("executing scalar query");
		$r = $this->statement->execute();
		$this->log(' yielded ' . byn($r));
		$this->statement->store_result();
		if ($this->statement->num_rows > 1)   // not checking could produce obscure error later
			throw new Exception("ScalarSelector retrieved {$this->statement->num_rows} rows from query $this->query");
		$r = $this->statement->fetch();
		$this->log(', fetch yielded ' . byn($r) . ".\n");
		$this->statement->free_result();
		return $result;
	}
}

class Inserter extends SqlStatement
{
	public function insert(...$params)			// table being inserted to must have an auto-increment column for this to return a value
	{
		$this->prepAndBind(...$params);
		$this->log('executing insert');
		$r = $this->statement->execute();
		$this->log(' yielded ' . byn($r) . ", producing key {$this->statement->insert_id}.\n");
		return $this->statement->insert_id;
	}
}

class Updater extends SqlStatement		// also use this for delete statements, or inserts with no auto-increment column
{
	public function update(...$params)
	{
		$this->prepAndBind(...$params);
		$this->log('executing update');
		$result = $this->statement->execute();
		$this->log(' yielded ' . byn($result) . ".\n");
		return $result;
	}
}

// In the classes with one method, it would have been cool to name it __invoke, but that didn't work.

?>
