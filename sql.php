<?php

// This is a simple PHP framework for encapsulating mysqli prepared statements so that they
// can be executed with one simple method, or two in the case of nonscalar select statements.
// It includes the capability to log each action to a common string buffer for error diagnosis.
// By Paul Kienitz, 2021, no license, no rights reserved.

// add Selector->getRowIntoObject($ob), which maps columns to properties via snake2camel?


class SqlLogger
{
    const TimeThreshold = 0.25;

    public bool   $loggingIsActive = true;
    public string $log = '';

    private $lastLoggedTime = null;

    public function __construct(bool $active)
    {
        $this->loggingIsActive = $active;
    }

    public static function byn($b): string    // format something you normally expect to be boolean but may be in numeric form
    {
        if (is_null($b))
            return 'null';
        else if (is_string($b))
            return '"' . addslashes($b) . '"';
        else
            return $b ? 'true' : 'false';
    }
    
    public static function nust($v): string    // format something you expect to be number or string or actual boolean
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

    public function log($msg)
    {
        if (!$this->loggingIsActive)
            return;
        $t = hrtime(true) / 1000000000.0;
        $diff = $t - ($this->lastLoggedTime ?? $t);
        if ($diff >= self::TimeThreshold)
            $this->log .= ' ##[' . number_format($diff, 3) . '] ';
        $this->lastLoggedTime = $t;
        $this->log .= $msg;
    }

    public static function shortFilePaths(string $textWithPaths): string
    {
        return str_replace($_SERVER['DOCUMENT_ROOT'], '', $textWithPaths);
    }
    
    public static function formatThrowable(?Throwable $ex): ?string
    {
        if (!$ex)
            return null;
        $type = get_class($ex) . ($ex->getCode() ? ' (' . $ex->getCode() . ')' : '');
        $line = self::shortFilePaths($ex->getFile()) . ' line ' . $ex->getLine();
        $trace = self::shortFilePaths("\n" . $ex->getTraceAsString());
        $inner = $ex->getPrevious() ? "\n---- Caused by:\n" . formatThrowable($ex->getPrevious()) : '';
        return $type . ' at ' . $line . ': ' . $ex->getMessage() . $trace . $inner;
    }
}


class SqlStatement
{
    protected mysqli       $marie;              // a mysqli connection
    protected string       $query;              // the text of the SQL statement to prepare
    protected string       $paramTypes;         // binding types string -- length must equal number of "?" placeholders in query
    protected ?mysqli_stmt $statement = null;   // our raison-d'etre, after bind() is called
    protected ?SqlLogger   $logger;             // passed in so statements can share

    public function __construct(mysqli &$marie, ?SqlLogger $logger, string $paramType, string $query, bool $prepEarly = false)
    {
        $this->marie     = $marie;
        $this->paramType = $paramType;
        $this->query     = $query;
        $this->logger    = $logger;
        if ($prepEarly)
            $this->statement = $this->marie->prepare($this->query);
    }

    // call this first in any derived class method that executes the statement
    protected function bind(&...$params)
    {
        // lazy evaluation -- generally we only prepare the statement if it gets used
        $this->log("$this->query:\n-- already prepared? " . SqlLogger::byn(!!$this->statement));
        if (!$this->statement)
            $this->statement = $this->marie->prepare($this->query);
        $this->log(', ' . count($params) . " params '$this->paramType'.\n");
        if ($this->paramType && count($params))
            $this->statement->bind_param($this->paramType, ...$params);
    }

    protected function log($msg)
    {
        if ($this->logger)
            $this->logger->log($msg);
    }

    protected function logParams(&$params)
    {
        $sep = '';
        foreach ($params as $p)
        {
            $this->log($sep . SqlLogger::nust($p));
            $sep = ', ';
        }
    }
}


class Selector extends SqlStatement
{
    private $rowsFound = 0;
    private $rowsGotten = 0;
    private $exhausted = false;

    public function select(...$params): bool   // call this, then call getRow one or more times
    {
        $this->exhausted = false;
        $this->bind(...$params);
        $this->log("Executing select with params ");
        $this->logParams($params);
        $result = $this->statement->execute();
        $this->log(' yielded ' . SqlLogger::byn($result));
        $this->statement->store_result();
        $this->rowsFound = $this->statement->num_rows;
        $this->log(" and found {$this->rowsFound} rows.\n");
        return $result;
    }

    public function getRow(&...$results): bool   // call only after select() succeeds; one result var must be passed for each column
    {
        if ($this->exhausted)
            return false;
        $this->log('Binding results ');
        $this->statement->bind_result(...$results);
        $this->log("and fetching row $this->rowsGotten");
        $result = $this->statement->fetch();
        $this->log(' yielded ' . SqlLogger::byn($result) . '. ');
        if ($result)
            $this->rowsGotten++;
        if ($this->rowsGotten >= $this->rowsFound) {
            $this->log("\nAll $this->rowsGotten rows fetched, freeing.\n");
            $this->statement->free_result();
            $this->exhausted = true;
        }
        return $result ?? false;
    }
}

class ScalarSelector extends SqlStatement
{
    public function select(...$params)          // statement must return only one row containing only one field
    {
        $result = '';
        $this->bind(...$params);
        $this->log('Binding result ');
        $this->statement->bind_result($result);
        $this->log("and executing scalar query with params ");
        $this->logParams($params);
        $r = $this->statement->execute();
        $this->log(' yielded ' . SqlLogger::byn($r));
        $this->statement->store_result();
        if ($this->statement->num_rows > 1)   // not checking could produce obscure error later
            throw new Exception("ScalarSelector retrieved {$this->statement->num_rows} rows from query $this->query");
        $r = $this->statement->fetch();
        $this->log(', fetch yielded ' . SqlLogger::byn($r) . ".\n");
        $this->statement->free_result();
        return $result;
    }
}

class Inserter extends SqlStatement
{
    public function insert(...$params)          // table being inserted to must have an auto-increment column for this to return a value
    {
        $this->bind(...$params);
        $this->log('Executing insert of values ');
        $this->logParams($params);
        $r = $this->statement->execute();
        $this->log(' yielded ' . SqlLogger::byn($r) . ", adding {$this->marie->affected_rows} rows and producing key {$this->statement->insert_id}.\n");
        return $this->statement->insert_id;
    }
}

class Updater extends SqlStatement      // also use this for delete statements, or inserts with no auto-increment column
{
    public function update(...$params): bool
    {
        $this->bind(...$params);
        $this->log('Executing update with values ');
        $this->logParams($params);
        $result = $this->statement->execute();
        $this->log(' yielded ' . SqlLogger::byn($result) . " and updated {$this->marie->affected_rows} rows.\n");
        return $result;
    }
}

// In the classes with one method, it would have been cool to name it __invoke, but that didn't work.

?>