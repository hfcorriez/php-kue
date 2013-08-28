<?php

namespace Kue;

use Pagon\Fiber;

class Kue extends Fiber
{
    protected $injectors = array(
        'host'   => 'localhost',
        'port'   => '6379',
        'db'     => 1,
        'client' => null
    );

    /**
     * @var Kue
     */
    public static $instance;

    public $client;

    public static function createQueue(array $options = array())
    {
        if (!self::$instance) {
            self::$instance = new self($options);
        }
        return self::$instance;
    }

    public static function handleError()
    {
        set_error_handler(function ($type, $message, $file, $line) {
            if (error_reporting() & $type) throw new \ErrorException($message, $type, 0, $file, $line);
        });
    }

    public function __construct(array $options = array())
    {
        $this->injectors = $options + $this->injectors;

        $this->client = & $this->injectors['client'];

        if (!$this->client) {
            $this->client = new \Redis();
            $this->client->connect($this->injectors['host'], $this->injectors['port']);
            if ($this->injectors['db']) {
                $this->client->select($this->injectors['db']);
            }
        }
    }

    public function create($type, array $data = array())
    {
        $this->emit('create', $type, $data);
        return new Job($type, $data);
    }

    public function process($type, $fn = null)
    {
        $this->emit('process', $type, $fn);
        $worker = new Worker($this, $type);
        $worker->start($fn);
    }

    public function setting($name)
    {
        return $this->client->hget('q:settings', $name);
    }

    public function types()
    {
        return $this->client->smembers('q:job:types');
    }

    public function state($state)
    {
        return $this->client->zrange('q:jobs:' . $state, 0, -1);
    }

    public function card($state)
    {
        return $this->client->zcard('q:jobs:' . $state);
    }

    public function complete()
    {
        return $this->state('complete');
    }

    public function failed()
    {
        return $this->state('failed');
    }

    public function inactive()
    {
        return $this->state('inactive');
    }

    public function active()
    {
        return $this->state('active');
    }

}