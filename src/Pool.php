<?php

namespace Sohris\Mysql;

use React\EventLoop\Loop;
use React\Promise\Deferred;
use Sohris\Mysql\Io\Connector;
use Sohris\Mysql\Io\Query;

final class Pool
{
    private string $user;
    private string $password;
    private string $host;
    private string $database;
    private int $port;
    private string $socket;

    private static $timer;
    private static $min_pool = 2;

    private static $connections = [];
    private static int $current;

    public function __construct(
        ?string $user = '',
        ?string $password = '',
        ?string $host = '',
        ?int $port = 3306,
        ?string $database = '',
        ?string $socket = ''
    ) {
        $user | $this->user = $user;
        $password | $this->password = $password;
        $host | $this->host = $host;
        $port | $this->port = $port;
        $database | $this->database = $database;
        $socket | $this->socket = $socket;
    }

    public function setMinPoolSize(int $size)
    {
        self::$min_pool = $size;
    }

    public function exec(string $query, array $parameters = [])
    {
        $query = new Query($query, $parameters);
        $this->create();
        $this->current()->setDeferred(new Deferred());   
        echo $this->current()->id . PHP_EOL;     
        $this->current()->runQuery($query->getSQL(), MYSQLI_ASYNC | MYSQLI_STORE_RESULT);
        $this->validTimer();
        return $this->current()->promise();
    }

    private function create()
    {
        foreach (self::$connections as $id => $conn) {
            if (!$conn->running) {
                self::$current = $id;
                return;
            }
        }

        $conn =  new Connector($this->user, $this->password, $this->host, $this->port,  $this->database, $this->socket);
        self::$connections[$conn->id] = $conn;
        self::$current = $conn->id;
    }

    private function current(): Connector
    {
        return self::$connections[self::$current];
    }

    private function validTimer()
    {
        if (!isset(self::$timer))
            self::$timer = Loop::addPeriodicTimer(0.001, fn () => $this->check());
    }

    private function check()
    {
        if (empty(self::$connections)) {
            Loop::cancelTimer(self::$timer);
            return;
        }
        $links = $err = $rej = self::$connections;
        if (!mysqli_poll($links, $err, $rej, 1))
            return;

        foreach ($links as $key => &$connection) {
            $connection->finish();
            if (self::$min_pool < count(self::$connections))
                unset(self::$connections[$connection->id]);
        }
    }
}
