<?php

namespace fk\sort;

include_once __DIR__ . '/core/lftrgt.class.php';

class LeftRight extends LftRgt
{
    public $database;
    public $user;
    public $password;
    public $host;
    public $port = 3306;
    public $socket = null;
    public $charest = 'UTF8';
    
    public function __construct($table)
    {
        $config = get_class_vars($this);
        parent::__construct($config, $table);
    }
}