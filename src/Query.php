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

final class Query
{

    private $sql = '';
    private $parameters = [];

    private static $logger;


    public function __construct(string $query, array $parameters)
    {
        self::firstRun();
        $this->sql = $query;
        $this->parameters = $parameters;
        
    }

    private static function firstRun()
    {
        if (!self::$logger) {
            self::$logger = new Logger("MysqlQueries");
        }
    }
    
    private function resolveSQLValue($value)
    {
        $type = gettype($value);
        switch ($type) {
            case 'boolean':
                $value = (int) $value;
                break;
            case 'double':
            case 'integer':
                break;
            case 'string':
                $value = "'" . Mysql::realEscapeString($value) . "'";
                break;
            case 'array':
                $nvalue = [];
                foreach ($value as $v) {
                    $nvalue[] = $this->resolveSQLValue($v);
                }
                $value = implode(',', $nvalue);
                break;
            case 'NULL':
                $value = 'NULL';
                break;
            default:
                break;
        }
        return $value;
    }

    private function bindParams()
    {
        $sql = $this->sql;
        $offset = strpos($sql, '?');
        foreach ($this->parameters as $param) {
            $replacement = $this->resolveSQLValue($param);
            $sql = substr_replace($sql, $replacement, $offset, 1);
            $offset = strpos($sql, '?', $offset + strlen($replacement));
        }

        return $sql;

    }

    public function getSQL()
    {
        return $this->bindParams();
    }

}