<?php

namespace App;

class DbConfig
{
    /**
     * @var \PDO
     */
    protected $connection;

    /**
     * DbConfig constructor.
     * @param $db
     * @param $host
     * @param $username
     * @param $password
     */
    public function __construct($db, $host, $username, $password)
    {
        $this->connection = new \PDO("mysql:dbname=$db;host=$host", $username, $password);
        $this->connection->query("SET NAMES 'utf8'");

    }

    /**
     * @return \PDO
     */
    public function getConnection()
    {
        return $this->connection;
    }

}

