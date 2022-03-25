<?php

namespace Sohris\Mysql;

use React\EventLoop\Loop;
use Sohris\Core\Component\AbstractComponent;
use Sohris\Core\Logger;
use Sohris\Core\Utils;

class Component extends AbstractComponent
{
    private $module_name = "Sohris_Mysql";

    private $logger;

    private $loop;

    private $configs = array();

    public function __construct()
    {
        $this->configs = Utils::getConfigFiles('mysql');
        $this->loop = Loop::get();
        $this->logger = new Logger('Mysql');
    }

    public function install()
    {
    }

    public function start()
    {
    }

    public function startPool()
    {

        Pool::createConnection();

        Pool::startPool();
    }

    public function getName(): string
    {
        return $this->module_name;
    }
}
