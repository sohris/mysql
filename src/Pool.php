<?php

namespace Sohris\Mysql;

use Exception;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use Sohris\Core\Logger;
use Sohris\Core\Utils;
use Throwable;

final class Pool
{
    private static $list_of_mysqli = [];

    private static $query_list = [];

    private static $configs = [];

    /**
     * @var LoopInterface
     */
    private static $loop;
    private static $logger;

    public static function createConnection()
    {
        self::firstRun();
        self::$logger->debug(self::$configs['pool_size'] . " Connection In Pool");
        try {
            for ($i = 0; $i < self::$configs['pool_size']; $i++) {
                self::$list_of_mysqli[] = new Mysql;
            }
        } catch (\Throwable $e) {
            echo $e->getMessage();
        }
    }

    private static function firstRun()
    {
        if (!self::$configs) {
            self::$configs = Utils::getConfigFiles('mysql');
        }

        if (!self::$logger) {
            self::$logger = new Logger("Mysql");
        }
        if (!array_key_exists('pool_size', self::$configs)) {
            self::$configs['pool_size'] = 1;
        }

        if (!self::$loop) {
            self::$loop = Loop::get();
        }
    }

    public function exec(string $query, array $parameters = []): PromiseInterface
    {
        $sql = new Query($query, $parameters);
        return Mysql::queueQuery($sql);
    }

    public function checkIfNeededMoreConnections()
    {
        if($this->isOverloaded())
        {
            self::$list_of_mysqli[] = new Mysql;
        }
    }
    
    private function isOverloaded()
    {
        return Mysql::getQueueCount() >= sizeof(self::$list_of_mysqli);
    }
}