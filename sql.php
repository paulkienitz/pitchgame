<?php

// This is a simple PHP framework for encapsulating mysqli prepared statements so that they
// can be executed with one simple method, or two in the case of nonscalar select statements.
// It includes the capability to log each action to a common string buffer for error diagnosis.
// By Paul Kienitz, 2021, no license, no rights reserved.

function f_byn($b)    // format something you normally expect to be boolean but may be in numeric form
{
	if (is_null($b))
		return 'null';
	else if (is_string($b))
		return '"' . addslashes($b) . '"';
	else
		return $b ? 'true' : 'false';
}

function f_num($v)    // format something you expect to be number or string or actual boolean
{
	if (is_null($v))
		return 'null';
	else if (is_bool($v))
		return $v ? 'true' : 'false';
	else if (is_numeric($v))
		return (string) $v;
	else
		return '"' . addslashes((string) $v) . '"';
}

class SqlStatement
{
	protected $marie;				// a mysqli object
	protected $query;				// the text of the SQL statement to prepare
	protected $paramTypes;			// binding types string -- length must equal number of "?" placeholders in query
	protected $statement;			// our mysqli_stmt object, after prepAndBind() is called
	private $logtext;				// a reference to a string variable used for logging output

	public function __construct(mysqli &$marie, ?string &$log, string $paramType, string $query)
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
		$this->log("$this->query:\n-- already prepared? " . f_byn(!!$this->statement));
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

	protected function logParams(&$params)
	{
		foreach ($params as $p)
		{
			$this->log($sep . f_num($p));
			$sep = ', ';
		}
	}
}


class Selector extends SqlStatement
{
	private $rowsFound = 0;
	private $rowsGotten = 0;
	private $exhausted = false;

	public function select(...$params)		// call this, then call getRow one or more times
	{
		$this->exhausted = false;
		$this->prepAndBind(...$params);
		$this->log("executing select with params ");
		$this->logParams($params);
		$result = $this->statement->execute();
		$this->log(' yielded ' . f_byn($result));
		$this->statement->store_result();
		$this->rowsFound = $this->statement->num_rows;
		$this->log(" and found {$this->rowsFound} rows.\n");
		return $result;
	}

	public function getRow(&...$results)	// call only after select() succeeds; one result var must be passed for each column
	{
		if ($this->exhausted)
			return null;
		$this->statement->bind_result(...$results);
		$this->log("Fetching row $this->rowsGotten");
		$result = $this->statement->fetch();
		$this->log(' yielded ' . f_byn($result) . '. ');
		if ($result)
			$this->rowsGotten++;
		if ($this->rowsGotten >= $this->rowsFound) {
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
		$this->log("executing scalar query with params ");
		$this->logParams($params);
		$r = $this->statement->execute();
		$this->log(' yielded ' . f_byn($r));
		$this->statement->store_result();
		if ($this->statement->num_rows > 1)   // not checking could produce obscure error later
			throw new Exception("ScalarSelector retrieved {$this->statement->num_rows} rows from query $this->query");
		$r = $this->statement->fetch();
		$this->log(', fetch yielded ' . f_byn($r) . ".\n");
		$this->statement->free_result();
		return $result;
	}
}

class Inserter extends SqlStatement
{
	public function insert(...$params)			// table being inserted to must have an auto-increment column for this to return a value
	{
		$this->prepAndBind(...$params);
		$this->log('executing insert of values ');
		$this->logParams($params);
		$r = $this->statement->execute();
		$this->log(' yielded ' . f_byn($r) . ", adding {$this->marie->affected_rows} rows and producing key {$this->statement->insert_id}.\n");
		return $this->statement->insert_id;
	}
}

class Updater extends SqlStatement		// also use this for delete statements, or inserts with no auto-increment column
{
	public function update(...$params)
	{
		$this->prepAndBind(...$params);
		$this->log('executing update with values ');
		$this->logParams($params);
		$result = $this->statement->execute();
		$this->log(' yielded ' . f_byn($result) . " and updated {$this->marie->affected_rows} rows.\n");
		return $result;
	}
}

// In the classes with one method, it would have been cool to name it __invoke, but that didn't work.

?>
