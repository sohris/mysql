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

    public function __construct(
        ?string $user = '',
        ?string $password = '',
        ?string $host = '',
        ?int $port = 3306,
        ?string $database = '',
        ?string $socket = ''
    ) {

        parent::__construct();
        parent::real_connect($host, $user, $password, $database, $port, $socket);
        $this->id = random_int(11111, 99999);
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
        mysqli_next_result($this);
        if ($result = mysqli_store_result($this))
            mysqli_free_result($result);

        $this->running = false;
    }


    public function runQuery($query)
    {
        $this->running = true;        
        return $this->query($query, MYSQLI_ASYNC | MYSQLI_STORE_RESULT);
    }
}
