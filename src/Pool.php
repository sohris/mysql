<?php

namespace Sohris\Mysql;

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use Sohris\Core\Logger;
use Sohris\Core\Utils;

final class Pool
{
    private static $list_of_mysqli = [];

    private static $query_list = [];

    private static $configs = [];

    /**
     * @var LoopInterface
     */
    private static $loop;

    private static $query_count = 0;

    private static $logger;

    private static $query_list_timer;
    private static $query_resolver_timer;

    private static $next_conn_config;
    private static $next_query_config;

    public static function createConnection()
    {
        self::firstRun();
        self::$logger->debug(self::$configs['pool_size'] . " Connection In Pool");
        try {
            for ($i = 0; $i < self::$configs['pool_size']; $i++) {
                self::$list_of_mysqli[$i] = [
                    "connection" => \mysqli_connect(self::$configs['host'], self::$configs['user'], self::$configs['pass'], self::$configs['base'], self::$configs['port']),
                    "query_exec" => null,
                    "status" => 'sleeping',
                    "key" => $i
                ];
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

    public static function startPool()
    {
        self::$query_list_timer = self::$loop->addPeriodicTimer(0.001, fn () => self::checkNextQuery());
        self::$query_resolver_timer = self::$loop->addPeriodicTimer(0.001, fn () => self::checkResolveQuery());
    }

    private static function checkNextQuery()
    {
        //Not available connection
        if (!$conn_config = self::getSleepConnection()) return;

        //Not query in queue
        if (!$query_config = self::getNextQuery()) return;
        self::$next_conn_config = null;
        self::$next_query_config = null;

        $conn = $conn_config['connection'];
        $conn_key = $conn_config['key'];
        $query = $query_config['query'];
        $query_key = $query_config['key'];


        $conn->query($query, MYSQLI_ASYNC);

        self::setQueryRunning($query_key);
        self::setConnectionRunning($conn_key, $query_key);
    }

    private static function checkResolveQuery()
    {
        foreach (array_filter(self::$list_of_mysqli, fn ($mi) => $mi['status'] == 'running') as $link) {
            $links = $errors = $rejects = [];
            $links[] = $errors[] = $rejects[] = $link['connection'];
            $conn_key = $link['key'];
            if (!mysqli_poll($links, $errors, $rejects, false, 1000)) {
                continue;
            }

            foreach ($links as $conn) {
                if (!$result = $conn->reap_async_query())
                    self::rejectQuery($conn_key, mysqli_error($conn));
                if (!$result)
                    continue;
                else if (!$result === true) {
                    self::resolveQuery($conn_key, $result);

                    if (is_object($result))
                        mysqli_free_result($result);
                } else {
                    $resolve = [];
                    while ($row = $result->fetch_assoc()) {
                        $resolve[] = $row;
                    }
                    self::resolveQuery($conn_key, $resolve);

                    if (is_object($result))
                        mysqli_free_result($result);
                }
            }
        }
    }

    private static function rejectQuery($connection_key, $erro_msg)
    {
        $query_key =  self::$list_of_mysqli[$connection_key]['query_key'];
        $query_config = self::$query_list[$query_key];
        $deferrend = $query_config['deferrend'];
        $deferrend->reject($erro_msg);
        self::clearConnection($connection_key);
        self::clearQueryList($query_key);
    }

    private static function resolveQuery($connection_key, $result)
    {
        $query_key =  self::$list_of_mysqli[$connection_key]['query_key'];
        $query_config = self::$query_list[$query_key];
        $deferrend = $query_config['deferrend'];
        $deferrend->resolve($result);
        self::clearConnection($connection_key);
        self::clearQueryList($query_key);
    }

    private static function clearConnection($connection_key)
    {
        self::$list_of_mysqli[$connection_key]['status'] = 'sleeping';
        self::$list_of_mysqli[$connection_key]['query_key'] = null;
    }

    private static function clearQueryList($query_key)
    {
        unset(self::$query_list[$query_key]);
    }

    private static function setQueryRunning($key)
    {
        self::$query_list[$key]['status'] = 'running';
    }
    private static function setConnectionRunning($key, $query_key)
    {
        self::$list_of_mysqli[$key]['status'] = 'running';
        self::$list_of_mysqli[$key]['query_key'] = $query_key;
    }

    private static function getNextQuery()
    {
        if (self::$next_query_config)
            return self::$next_query_config;

        $waiting_queries = array_filter(self::$query_list, fn ($q) => $q['status'] == 'waiting');
        if (!empty($waiting_queries)) {
            self::$next_query_config = array_shift($waiting_queries);
            return self::$next_query_config;
        }

        return null;
    }

    private static function getSleepConnection()
    {
        if (self::$next_conn_config)
            return self::$next_conn_config;

        $filter = array_filter(self::$list_of_mysqli, fn ($connection) => $connection['status'] == 'sleeping');
        if (!empty($filter)) {
            self::$next_conn_config = array_shift($filter);
            return self::$next_conn_config;
        }
        return null;
    }

    public function exec($query): PromiseInterface
    {
        $deferrend = new Deferred();

        $key = self::getNextCount();
        self::$query_list[$key] = [
            "key" => $key,
            "query" => $query,
            "deferrend" => $deferrend,
            "status" => "waiting"
        ];

        return $deferrend->promise();
    }

    private static function getNextCount()
    {
        if (self::$query_count >= 254)
            self::$query_count = 0;
        ++self::$query_count;
        return self::$query_count;
    }
}
