<?php

namespace Sohris\Mysql;

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
        parent::__construct(self::$configs['host'], self::$configs['user'], self::$configs['pass'], self::$configs['base'], self::$configs['port']);
        $this->query("SET @@time_zone='UTC';");
        if (key_exists('charset', self::$configs))
            $this->set_charset(self::$configs['charset']);
        $this->startTimer();
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
            self::$any_connection = $this;
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

        if (!$this->ping()) {
            self::$logger->critical("Mysql Server has gone away!");
        }

        $this->stopTimer();
        $this->query_running = self::$query_list->dequeue();
        $this->query($this->query_running['query']->getSQL(), MYSQLI_ASYNC | MYSQLI_USE_RESULT);
        $this->timer_check_query = $this->loop->addPeriodicTimer(0.001, fn () => $this->checkPoll());
    }

    private function checkPoll()
    {
        $links = $err = $rej = [];
        $links[] = $err[] = $rej[] = $this;

        try {
            if (!mysqli_poll($links, $err, $rej, false, 10000))
                return;
            if (!$result = $this->reap_async_query())
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
        $this->query_running['deferrend']->reject($this->error);
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
        mysqli_next_result($this);
        if ($result = mysqli_store_result($this))
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
        return self::$any_connection->real_escape_string($string);
    }

    public static function getQueueCount()
    {
        return self::$query_list->count();
    }
}
