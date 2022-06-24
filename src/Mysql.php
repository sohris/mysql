<?php

namespace Sohris\Mysql;

use mysqli;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use Sohris\Core\Logger;
use Sohris\Core\Utils;
use SplQueue;
use Throwable;

class Mysql extends \mysqli
{

    const CHUNK_SIZE = 2048;

    /**
     * @var LoopInterface
     */
    private $loop;

    private $mysql;
    private $timer;

    private $query_running;

    private static $any_connection;
    /**
     * @var \SplQueue
     */
    private static $query_list;

    private static $configs = [];
    private static $logger;
    private $last_running;


    public function __construct()
    {
        $this->firstRun();
        $this->loop = Loop::get();
        $this->mysql = self::createConnection();
        $this->startTimer();
    }

    private static function createConnection()
    {
        try {
            $mysql = new mysqli(self::$configs['host'], self::$configs['user'], self::$configs['pass'], self::$configs['base'], self::$configs['port']);
            $mysql->query("SET @@time_zone='UTC';");
            if (key_exists('charset', self::$configs))
                $mysql->set_charset(self::$configs['charset']);
            return $mysql;
        } catch (Throwable $e) {
            self::$logger->critical("Can not create Mysqli Connection!", [$e->getMessage()]);
        }
        return null;
    }

    public function firstRun()
    {
        if (!self::$query_list) {
            self::$query_list = new SplQueue;
        }

        if (!self::$configs) {
            self::$configs = Utils::getConfigFiles('mysql');
        }

        if (!self::$logger) {
            self::$logger = new Logger("Mysql");
        }

        if (!self::$any_connection)
            self::$any_connection = $this->mysql;
    }

    private function startTimer()
    {
        $this->timer = $this->loop->addPeriodicTimer(0.001, fn () => $this->nextQuery());
    }

    private function stopTimer()
    {
        $this->loop->cancelTimer($this->timer);
    }

    public static function queueQuery($query)
    {
        $deferrend = new Deferred();

        self::$query_list->enqueue([
            "query" => $query,
            "deferrend" => &$deferrend
        ]);
        return $deferrend->promise();
    }

    private function nextQuery()
    {
        if (self::$query_list->isEmpty())
            return;
        
        if (!$this->mysql->ping() || !$this->mysql) {
            $this->mysql = $this->createConnection();
            self::$logger->critical("Reconnect Mysql!");
            return;
        }

        $this->stopTimer();
        $this->query_running = self::$query_list->dequeue();
        $this->mysql->query($this->query_running['query']->getSQL(), MYSQLI_ASYNC | MYSQLI_USE_RESULT);
        $this->timer_check_query = $this->loop->addPeriodicTimer(0.001, fn () => $this->checkPoll());
    }

    private function checkPoll()
    {
        $links = $err = $rej = [];
        $links[] = $err[] = $rej[] = $this->mysql;

        try {
            if (!mysqli_poll($links, $err, $rej, false, 10000))
                return;
            if (!$result = $this->mysql->reap_async_query())
                $this->rejectQuery();
            else
                $this->resolveQuery($result);
            $this->freeConnection();
        } catch (Throwable $e) {
            echo $e->getMessage();
        }
    }

    private function rejectQuery()
    {
        $this->query_running['deferrend']->reject($this->mysql->error);
    }

    private function resolveQuery(&$result)
    {
        if ($result === true)
            return $this->query_running['deferrend']->resolve($result);
        return $this->query_running['deferrend']->resolve($result->fetch_all(MYSQLI_ASSOC));
    }

    public function running()
    {
        return !empty($this->query_running);
    }

    private function freeConnection()
    {
        mysqli_next_result($this->mysql);
        if ($result = mysqli_store_result($this->mysql))
            mysqli_free_result($result);
        $this->loop->cancelTimer($this->timer_check_query);
        unset($this->query_running);
        $this->startTimer();
        $this->last_running = Utils::microtimeFloat();
    }

    public function soSleep()
    {
        return $this->last_running && Utils::microtimeFloat() - $this->last_running >= 60;
    }

    public static function realEscapeString(string $string)
    {   
        if(!self::$any_connection || !self::$any_connection->ping())
           self::$any_connection = self::createConnection();
        return self::$any_connection->real_escape_string($string);
    }

    public static function getQueueCount()
    {
        return self::$query_list->count();
    }
}
