<?php

namespace Sohris\Mysql;

use Exception;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;
use Sohris\Core\Logger;
use Sohris\Core\Utils;

final class Pool
{

    private static $list_of_mysqli = [];
    private static $configs = [];

    /**
     * @var LoopInterface
     */
    private static $loop;
    private static $logger;
    private static $queries_runned = 0;
    private static $queries_rejectes = 0;
    private static $queries_timeout = 0;
    private static $list_rejected_queries = [];

    public static function createConnection()
    {

        self::firstRun();
        self::$logger->debug(self::$configs['pool_size'] . " Connection In Pool");
        try {
            for ($i = 0; $i < self::$configs['pool_size']; $i++) {
                self::$list_of_mysqli[] = new Connector;
            }
        } catch (\Throwable $e) {
            self::$logger->throwable($e);
        }
    }

    private static function firstRun()
    {
        if (!self::$list_of_mysqli)
            self::$list_of_mysqli = [];

        if (!self::$configs) {
            self::$configs = Utils::getConfigFiles('mysql');
        }

        if (!self::$logger) {
            self::$logger = new Logger("PoolMysql");
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
        self::$logger->debug("Define Query " . $query, $parameters);
        $sql = new Query($query, $parameters);
        self::$queries_runned++;
        return Connector::queueQuery($sql)->then(fn ($result) => $result, function (Exception $e) use ($query, $parameters) {
            self::$logger->debug("Error Query " . $query);
            self::$logger->exception($e);
            self::$list_rejected_queries[] = [
                'query' => $query,
                'params' => $parameters
            ];
            if ($e->getMessage() == "QUERY_TIMEOUT")
                self::$queries_timeout++;
            else
                self::$queries_rejectes++;
           
            return $e;
        });
    }

    private static function isOverloaded()
    {
        $diff = Connector::getQueueCount() - count(self::$list_of_mysqli);
        return $diff >= (3 * count(self::$list_of_mysqli)) ? $diff : 0;
    }

    public static function checkConnection()
    {

        if (count(self::$list_of_mysqli) <  self::$configs['pool_size']) {
            for ($i = 0; $i < self::$configs['pool_size'] - count(self::$list_of_mysqli); $i++) {
                self::$list_of_mysqli[] = new Connector;
            }
        }

        $ov = self::isOverloaded();
        if ($ov > 0) {
            for ($i = 0; $i < (int)($ov / 3); $i++) {
                self::$list_of_mysqli[] = new Connector;
            }
        }

        if (count(self::$list_of_mysqli) ==  self::$configs['pool_size']) return;
        for ($i = 0; $i < count(self::$list_of_mysqli); $i++) {
            if (self::$list_of_mysqli[$i] instanceof Connector && self::$list_of_mysqli[$i]->soSleep()) {
                self::$list_of_mysqli[$i]->close();
                unset(self::$list_of_mysqli[$i]);
            }
        }
        self::$list_of_mysqli = array_values(self::$list_of_mysqli);
    }

    public static function getStats()
    {
        return [
            'total_queries' => self::$queries_runned,
            'total_queries_runned' => self::$queries_runned - self::$queries_rejectes - self::$queries_timeout,
            'total_queries_rejected' => self::$queries_rejectes,
            'total_queries_timeout' => self::$queries_timeout,
            'current_pool' => count(self::$list_of_mysqli),
            'queue_queries' => Connector::getQueueCount()
        ];
    }

    public static function dumpRejectedQueries()
    {
        $queries = self::$list_rejected_queries;
        self::$list_rejected_queries = [];
        return $queries;
    }

}
