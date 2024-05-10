<?php

namespace Sohris\Mysql\Io;

use Exception;
use mysqli;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use Sohris\Mysql\QueryResult;

final class Connector extends mysqli
{
    private Deferred $deferred;
    public int $id;
    public bool $running = false;
    private string $query;
    private array $time = [];

    public function __construct(
        ?string $user = '',
        ?string $password = '',
        ?string $host = '',
        ?int $port = 3306,
        ?string $database = '',
        ?string $socket = '',
        int $connection_timeout = 5
    ) {
        parent::__construct();
        mysqli_options($this, MYSQLI_OPT_CONNECT_TIMEOUT, $connection_timeout);
        parent::connect($host, $user, $password, $database, $port, $socket);
        $this->id = random_int(11111, 99999);
    }

    public function setTimeout(int $timeout)
    {
        mysqli_options($this, MYSQLI_OPT_READ_TIMEOUT, $timeout);
    }

    public function __destruct()
    {
        $this->close();
    }

    public function setDeferred(Deferred $deferred)
    {
        $this->deferred = $deferred;
    }

    public function promise(): PromiseInterface
    {
        return $this->deferred->promise();
    }

    public function finish()
    {

        $this->time[1] = hrtime(true);
        try {
            if (!$result = $this->reap_async_query()) {
                $exception = new Exception($this->error, $this->errno);
                $this->deferred->reject($exception);
            } else {
                $query_result = new QueryResult();
                if ($result === true && $this->insert_id) {
                    $query_result->insertId = $this->insert_id;
                } else {
                    $query_result->affectedRows = $this->affected_rows;
                    $query_result->resultRows = $result->fetch_all(MYSQLI_ASSOC);
                }

                $this->deferred->resolve($query_result);
            }
        } catch (Exception $e) {
            $this->deferred->reject($e);
        }
        unset($this->deferred);
        mysqli_next_result($this);
        if ($result = mysqli_store_result($this))
            mysqli_free_result($result);

        $this->running = false;
    }


    public function runQuery($query)
    {
        $this->running = true;
        $this->query = $query;
        $this->time[0] = hrtime(true);
        return $this->query($query, MYSQLI_ASYNC | MYSQLI_STORE_RESULT);
    }

    public function getStats()
    {
        return "Query: " . $this->query . "\n" .
            "Time: " . ($this->time[1] - $this->time[0]) . "\n" .
            "Info: " . $this->info;
    }
}
