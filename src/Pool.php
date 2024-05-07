<?php

namespace Sohris\Mysql;

use Exception;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\MySQL\Factory;
use React\MySQL\Io\LazyConnection;
use React\MySQL\QueryResult;
use React\Promise\PromiseInterface;
use Sohris\Core\Logger;
use Sohris\Core\Utils;

use function React\Promise\reject;
use function React\Promise\resolve;

final class Pool
{
    private static LazyConnection $connection;
    private Factory $factory;
    private Logger $logger;

    public function __construct()
    {
        $this->factory = new Factory();
        $this->logger = new Logger('CoreMysql');
        $this->createConnection();
    }

    public function getUri()
    {
        $config = Utils::getConfigFiles("database");
        $user = rawurlencode($config['user']);
        $pass = rawurlencode($config['pass']);
        $port = isset($config['port']) ? $config['port'] : "3306";
        return "$user:$pass@$config[host]:$port/$config[base]?timeout=5";
    }

    public function valid()
    {
        if (!self::$connection) {
            $this->createConnection();
            return;
        }

        try {
            self::$connection->ping();
        } catch (Exception $e) {
            unset(self::$connection);
            $this->createConnection();
        }
        return;
    }

    public function createConnection()
    {
        try {
            self::$connection = $this->factory->createLazyConnection($this->getUri());
        } catch (Exception $e) {
            $this->logger->exception($e);
            throw $e;
        }
    }

    public function exec(string $query, array $parameters = []): PromiseInterface
    {
        $this->valid();
        return self::$connection->query($query, $parameters)->then(function (QueryResult $command) {
            if (isset($command->resultRows)) {
                return resolve($command->resultRows);
            }
            if ($command->insertId !== 0) {
                return resolve($command->insertId);
            }
            return resolve([]);
        }, function (Exception $error) {
            $this->logger->exception($error);
            return reject($error);
        });
    }

    public static function getStats()
    {
        return [];
    }
}
