<?php

class Query
{

    protected $select;
    protected $from;
    protected $join;
    protected $where;
    protected $group;
    protected $having;
    protected $order;
    protected $limit;
    protected $update;
    protected $delete;
    protected $using;
    protected $insert;

    /**
     *
     * @var mysqli
     */
    public static $db;
    public $query;

    public function __construct($dbConfig = array(), $force = true)
    {
        if ($force === true) {
            if (!isset(static::$db)) {
                if (empty($dbConfig)) {
                    $dbConfigFile = __DIR__ . '/dbconfig.php';
                    is_file($dbConfigFile) && $dbConfig = include $dbConfigFile;
                }
                $port = null;
                $socket = null;
                extract($dbConfig);
                if (empty($host) || empty($user) || empty($password) || empty($database) || empty($charset)) {
                    trigger_error('Invalid database configure');
                }
                static::$db = new Mysqli($host, $user, $password, $database, $port, $socket);
                mysqli_set_charset(static::$db, $charset);
            }
        }
    }

    public function select($select)
    {
        $selectInString = '';
        if ($select instanceof Expression) {
            $selectInString .= $select->expression;
        } else {
            $fields = is_string($select) ? explode(',', $select) : $select;
            foreach ($fields as $v) {
                if (0 === strpos($v, '`') || '*' === strstr($v, '*')) {
                    $selectInString .= ",$v";
                } else if (strpos($v, '.')) {
                    $selectInString .= ',`' . str_replace('.', '`.`', $v) . '`';
                } else {
                    $selectInString .= ',`' . $v . '`';
                }
            }
        }

        $select = ltrim($selectInString, ',');
        if (empty($this->select)) {
            $this->select = $select;
        } else {
            $this->select .= ',' . $select;
        }
        return $this;
    }

    public function from($from)
    {
        $this->from = $from;
        return $this;
    }

    public function join($join, $type = 'LEFT')
    {
        if (empty($this->join)) {
            $this->join = $type . ' JOIN ' . $join;
        } else {
            $this->join .= ' ' . $type . ' JOIN ' . $join;
        }
        return $this;
    }

    public function notSupported()
    {
        throw new Exception('Not supported yet');
    }

    /**
     * @param mixed $input
     * @return Expression|string
     * @throws Exception
     */
    protected function addBackQuotes(&$input)
    {
        if (is_string($input)) {
            return $input = strpos($input, '`') !== false || strpos($input, '.') !== false ? $input : "`$input`";
        } else {
            $this->notSupported();
        }
    }

    protected function addQuotes(&$input)
    {
        if (is_array($input)) {
            $this->notSupported();
        } else if (is_numeric($input)) {
            return $input;
        } else if (is_string($input)) {
            return $input = '"' . addslashes($input) . '"';
        } else if ($input instanceof Expression) {
            return $input = $input->expression;
        } else {
            throw new Exception('Invalid input for ' . __METHOD__);
        }
    }

    /**
     * @param mixed $where <br> Array or string
     * ```Array:
     *      [
     *          'id=1',
     *          ['id' => 1],
     *          ['id', '=', 1]
     *      ]
     * ```String
     *      'id=1'
     *
     * @param string $opt
     * @return $this|Query
     * @throws Exception
     */
    public function andWhere($where, $opt = 'AND')
    {
        if (!$where) {
            return $this;
        }
        if (is_array($where)) {
            $_where = '';
            foreach ($where as $k => &$v) {
                if (is_int($k)) {
                    if (is_array($v)) {
                        if (count($v) === 1) {
                            $field = key($v);
                            $operand = '=';
                            $value = $v[$field];
                        } else {
                            list ($field, $operand, $value) = $v;
                        }
                        $_where .= " $opt " . $this->addBackQuotes($field) . $operand . $this->addQuotes($value);
                    } else if (is_string($v)) {
                        $_where .= " opt $v";
                    } else {
                        $this->notSupported();
                    }
                } else {
                    $this->addBackQuotes($k);
                    $this->addQuotes($v);
                    $_where .= " $opt $k=$v";
                }
            }
            unset($v);
            $_where = substr($_where, strlen($opt) + 1);
        } else {
            $_where = $where;
        }
        return $this->where("($_where)", 'AND');
    }

    /**
     * Method take single dimension array only, which is associated, if $where is array
     * @param array|string $where
     * @param string $opt
     * @return $this
     */
    public function where($where, $opt = 'AND')
    {
        if (!$where) {
            return $this;
        }
        if (is_array($where)) {
            $_where = '';
            $k = key($where);
            $v = $where[$k];
            false === strpos($k, '`') && strpos($k, '.') && $k = '`' . str_replace('.', '`.`', $k) . '`';
            $_where .= "$k=" . ($v instanceof Expression || is_numeric($v) ? $v : '"' . addslashes($v) . '"');
        } else {
            $_where = $where;
        }
        if (empty($this->where)) {
            $this->where = $_where;
        } else {
            $this->where .= " $opt $_where";
        }
        return $this;
    }

    public function group($group)
    {
        if (empty($this->group)) {
            $this->group = $group;
        } else {
            $this->group .= $group;
        }
        return $this;
    }

    public function having($having)
    {
        if (empty($this->having)) {
            $this->having = $having;
        } else {
            $this->having .= $having;
        }
        return $this;
    }

    public function order($order)
    {
        if (empty($this->order)) {
            $this->order = $order;
        } else {
            $this->order .= ',' . $order;
        }
        return $this;
    }

    public function limit($opt1, $opt2 = null)
    {
        if (isset($opt2))
            $this->limit = $opt1 . ',' . $opt2;
        else
            $this->limit = $opt1;

        return $this;
    }

    /**
     * Method to get a string for update query
     * @param string $table
     * @param array $data
     * @return $this
     */
    public function update($table, $data)
    {
        $set = '';
        if (is_array($data)) {
            foreach ($data as $k => $v) {
                if (is_int($k)) {   // ["field=field expression"]
                    $set .= $this->addQuotes($v) . ',';
                } else {    // [field => value]
                    $this->addBackQuotes($k);
                    $this->addQuotes($v);
                    $set .= "$k=$v";
                }
            }
            $set = trim($set, ',');
        } else {
            $set = $data;
        }
        $this->update = 'UPDATE ' . $table . ' SET ' . $set;
        return $this;
    }

    /**
     *
     * @param string $table Table name
     * @param array $columns
     * @return int Inserted id
     */
    public function insert($table, $columns)
    {
        $this->filter($columns);
        if ($table && $columns) {
            false === strpos($table, '`') && $table = "`$table`";
            $this->insert = "INSERT INTO $table (" . implode(',', array_keys($columns)) . ') VALUES (' . implode(',', $columns) . ')';
            return $this->execute($this->insert);
        }
        return false;
    }

    /**
     * Filter and format data with back quotes
     * @param $data
     */
    protected function filter(&$data)
    {
        $_ = [];
        foreach ($data as $k => &$v) {
            0 === strpos($k, '`') || $k = "`$k`";
            $_[$k] = is_numeric($v) || $v instanceof Expression ? $v : '"' . addslashes($v) . '"';
        }
        $data = $_;
        unset($_);
    }

    public function insertAll($table, $columns)
    {

    }

    public function delete($table)
    {
        // delete from table where
        $this->delete = $table;
        return $this;
    }

    /**
     * Method to get the using condition
     * @param type $using
     * @return \Query
     */
    public function using($using)
    {
        $this->using = $using;
        return $this;
    }

    public function setQuery()
    {
        $query = '';

        if ($this->insert) {
            $query .= $this->insert;
        } else {
            if (empty($this->delete)) {
                if (empty($this->update)) {
                    empty($this->select) || $query .= 'SELECT ' . $this->select;
                    empty($this->from) || $query .= ' FROM ' . $this->from;
                    empty($this->join) || $query .= ' ' . $this->join;
                } else {
                    $query .= $this->update;
                }
            } else {
                $query .= 'DELETE FROM ' . $this->delete;
                empty($this->using) || $query .= ' USING (' . $this->using . ')';
            }

            empty($this->where) || $query .= ' WHERE ' . $this->where;

            if (empty($this->delete)) {
                if (empty($this->update)) {
                    if (!empty($this->group))
                        $query .= ' GROUP BY ' . $this->group;
                    if (!empty($this->having))
                        $query .= ' HAVING ' . $this->having;
                }
            }
            if (!empty($this->order))
                $query .= ' ORDER BY ' . $this->order;
            if (!empty($this->limit) || $this->limit === 0)
                $query .= ' LIMIT ' . $this->limit;
        }
        $this->query = $query;
        return $this;
    }

    /**
     * Method to clear the sql,to prepare for the next
     * @return Query
     */
    public function clearQuery()
    {
        $reflectionClass = new ReflectionClass(__CLASS__);
        $properties = $reflectionClass->getProperties(ReflectionProperty::IS_PROTECTED);
        foreach ($properties as &$property) {
            $propertyName = $property->getName();
            $this->$propertyName = null;
        }
        $this->query = null;
        return $this;
    }

    /**
     *
     * @param string|Query $query
     * @param boolean $returnObject
     * @return string
     */
    public function find($query = null, $returnObject = true)
    {
        $items = $this->execute($query, $returnObject);
        return (empty($items) || empty($items[0])) ? false : $items[0];
    }

    /**
     * Method to execute an query and return the result
     * @param string $query
     * @param boolean $returnObject If the rows are returned as object
     * @return boolean|array
     */
    public function execute($query = null, $returnObject = true)
    {
        if ($query) {
            $this->query = $query;
        } else {
            $query = $this->setQuery()->query;
        }
        if (empty($query)) {
            return false;
        } else {
            $result = static::$db->query($query);
        }
        // Print the mysql error information
        if (static::$db->errno) {
            $error[] = static::$db->error;
            $error[] = "The query executed: $query";
            Log::error($error);
        }

        if (!empty($this->insert)) {
            return static::$db->insert_id;
        }
        if (!is_bool($result)) {
            // When the result is a query and succeeded
            $key = 0;
            $rows = [];
            while ($row = $result->fetch_assoc()) {
                if ($returnObject) {
                    foreach ($row as $k => $v) {
                        isset($rows[$key]) || $rows[$key] = new Record();
                        $rows[$key]->$k = $v;
                    }
                } else {
                    $rows[$key] = $row;
                }
                $key++;
            }
            return $rows;
        } else {
            // When the sql sentence is not a query but some string like insert,update
            // Or the query returns false;
            return $result;
        }
    }

    /**
     * @param mixed $where Where clause, array or string
     * @return type
     */
    public function count($where = null)
    {
        $this->select = 'COUNT(*) AS c';
        $where && $this->where($where);
        $result = $this->find();
        return (int)$result->c;
    }

    public function close()
    {
        $closed = static::$db->close();
        static::$db = null;
        return $closed;
    }

    public function beginTransaction()
    {
        return method_exists(static::$db, 'begin_transaction') ? static::$db->begin_transaction() : $this->execute('START TRANSACTION');
    }

    public function commit()
    {
        return static::$db->commit();
    }

    public function rollback()
    {
        return static::$db->rollback();
    }

    public function __toString()
    {
        $this->setQuery();
        return $this->query;
    }

    public function __destruct()
    {
        $this->close();
    }

}

class Object
{

}

class Record extends stdClass implements ArrayAccess
{

    /**
     * Whether a offset exists
     * @link http://php.net/manual/en/arrayaccess.offsetexists.php
     * @param mixed $offset <p>
     * An offset to check for.
     * </p>
     * @return boolean true on success or false on failure.
     * </p>
     * <p>
     * The return value will be casted to boolean if non-boolean was returned.
     * @since 5.0.0
     */
    public function offsetExists($offset)
    {
        return property_exists($this, $offset);
    }

    /**
     * Offset to retrieve
     * @link http://php.net/manual/en/arrayaccess.offsetget.php
     * @param mixed $offset <p>
     * The offset to retrieve.
     * </p>
     * @return mixed Can return all value types.
     * @throws Exception When getting attribute not set
     * @since 5.0.0
     */
    public function offsetGet($offset)
    {
        if ($this->offsetExists($offset)) {
            return $this->$offset;
        } else {
            throw new Exception('Trying to get unknown attribute from ' . __CLASS__);
        }
    }

    /**
     * Offset to set
     * @link http://php.net/manual/en/arrayaccess.offsetset.php
     * @param mixed $offset <p>
     * The offset to assign the value to.
     * </p>
     * @param mixed $value <p>
     * The value to set.
     * </p>
     * @return void
     * @since 5.0.0
     */
    public function offsetSet($offset, $value)
    {
        $this->$offset = $value;
    }

    /**
     * Offset to unset
     * @link http://php.net/manual/en/arrayaccess.offsetunset.php
     * @param mixed $offset <p>
     * The offset to unset.
     * </p>
     * @return void
     * @since 5.0.0
     */
    public function offsetUnset($offset)
    {
        unset($this->$offset);
    }

    public function asArray()
    {
        return get_object_vars($this);
    }

}

class Expression
{

    public $expression;

    public function __construct($name)
    {
        $this->expression = $name;
    }

}

class Log
{

    static $message;

    public static function error($error)
    {
        $errors = is_array($error) ? $error : array($error);
        $errors[] = "Trace:";
        foreach (debug_backtrace() as $k => $trace) {
            $errors[] = @"#$k {$trace['file']}({$trace['line']}))";
        }
//        self::$message = implode("\n", $errors);
        self::$message = implode("<br/>", $errors);
        echo self::$message;
    }

}

class FK
{
    public static function t($category, $message, $params)
    {
        $messageFile = "prefix/$category.php";
        if (is_file($messageFile)) {
            $messages = include $messageFile;
            $result = empty($messages[$message]) ? $messages[$message] : $message;
        } else {
            $result = $message;
        }

        $search = $replace = [];
        foreach ($params as $k => &$v) {
            $search[] = "{$k}";
            $replace[] = $v;
        }
        unset($v);
        unset($params);

        $result = str_replace($search, $replace, $result);

        return $result;
    }
}

class ErrorHandler
{

    protected static $callback = null;

    public static function register($callback)
    {
        $callback instanceof Closure && self::$callback = $callback;
        set_error_handler([self, 'error']);

        set_exception_handler([self, 'exception']);
    }

    public static function unregister()
    {
        self::$callback = null;
        restore_error_handler();
        restore_exception_handler();
    }

    public static function error($no, $string, $file, $line, $context)
    {
        echo $string;
    }

    /**
     * @param Exception $exception
     * message
     * code
     * file
     * line
     * trace
     * previous
     */
    public static function exception($exception)
    {
        var_dump($exception);
    }
}