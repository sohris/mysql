<?php

namespace Sohris\Mysql;

use React\EventLoop\Loop;
use Sohris\Core\ComponentControl;
use Sohris\Core\Server;

class Mysql extends ComponentControl
{
    private $module_name = "Sohris_Mysql";

    private $server;

    public function __construct()
    {
        $this->server = Server::getServer();        
    }

    public function install()
    {
      
    }

    public function start()
    {
    }

    public function getName(): string
    {
        return $this->module_name;
    }

    public function getStats()
    {
        $stats = Pool::getStats();

        $stats['queries_per_second'] = $stats['total_queries_runned']/$this->server->getUptime();

        return $stats;
    }
}
