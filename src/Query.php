<?php

namespace Sohris\Mysql;

final class Query
{

    private $sql = '';
    private $parameters = [];

    public function __construct(string $query, array $parameters)
    {
        $this->sql = $query;
        $this->parameters = $parameters;

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
                $value = "'" . Connector::realEscapeString($value) . "'";
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