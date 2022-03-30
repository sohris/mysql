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
        self::startPool();
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

    public static function startPool()
    {
        // self::$loop->addPeriodicTimer(15, function () {
        //     $used_mem2 = Utils::bytesToHuman(memory_get_peak_usage());
        //     $used_mem4 = Utils::bytesToHuman(memory_get_peak_usage(true));
        //     echo "Memory $used_mem2 / $used_mem4 - ". PHP_EOL;
        // });
    }
    public function exec($query, $query_mode = 0): PromiseInterface
    {
        return Mysql::queueQuery($query, $query_mode);
    }
}