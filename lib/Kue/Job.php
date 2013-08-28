<?php
/**
 * Job.php.
 */

namespace Kue;

use Pagon\Fiber;

class Job extends Fiber
{
    protected static $PRIORITIES = array(
        'low'      => 10,
        'normal'   => 0,
        'medium'   => -5,
        'high'     => -10,
        'critical' => -15
    );

    protected $injectors = array(
        'id'                 => null,
        'type'               => null,
        'data'               => array(),
        'priority'           => 0,
        'progress'           => 0,
        'state'              => 'inactive',
        'error'              => '',
        'created_at'         => '',
        'updated_at'         => '',
        'failed_at'          => '',
        'duration'           => 0,
        'delay'              => 0,
        'attempts'           => 0,
        'attempts_remaining' => 1,
        'attempts_max'       => 1
    );

    /**
     * @var Kue
     */
    public $queue;

    /**
     * @var \Redis
     */
    public $client;

    public static function load($id)
    {
        $client = Kue::$instance->client;

        if (!$data = $client->hgetall('q:job:' . $id)) {
            return false;
        }

        $data['data'] = json_decode($data['data'], true);
        $job = new self($data['type'], $data['data']);
        $job->append($data);

        return $job;
    }

    public function __construct($type, $data = array())
    {
        $this->injectors['id'] = sha1(uniqid());
        $this->injectors['type'] = $type;
        $this->injectors['data'] = $data;
        $this->injectors['created_at'] = time();

        $this->queue = Kue::$instance;
        $this->client = Kue::$instance->client;
    }

    public function priority($pri)
    {
        if (is_numeric($pri)) {
            $this->injectors['priority'] = $pri;
        } else if (isset(self::$PRIORITIES[$pri])) {
            $this->injectors['priority'] = self::$PRIORITIES[$pri];
        }
    }

    public function attempt($fn)
    {
        $max = $this->get('attempts_max');
        $attempts = $this->client->hincrby('q:job:' . $this->injectors['id'], 'attempts', 1);
        $fn(max(0, $max - $attempts), $attempts, $max);
    }

    public function attempts($num)
    {
        $plus = $num - $this->injectors['attempts_max'];
        $this->injectors['attempts_max'] += $plus;
        $this->injectors['attempts_remaining'] += $plus;
    }

    public function delay($ms)
    {
        $this->injectors['delay'] = $ms;
        $this->injectors['state'] = 'delayed';
        return $this;
    }

    public function progress($pt)
    {
        $this->set('progress', min(100, $pt * 100));
        $this->set('updated_at', time());
    }

    public function error($error)
    {
        if (!$error) return $this->injectors['error'];

        if ($error instanceof \Exception) {
            $str = get_class($error) . ' Error on ' . $error->getFile() . ' ' . $error->getLine();
            $str .= $error->getTraceAsString();
        } else {
            $str = $error;
        }

        $this->set('error', $str);
        $this->set('failed_at', time());
        return $this;
    }

    public function complete()
    {
        return $this->set('progress', 100)->state('complete');
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

    public function removeState()
    {
        $this->client->zrem('q:jobs', $this->injectors['id']);
        $this->client->zrem('q:jobs:' . $this->injectors['state'], $this->injectors['id']);
        $this->client->zrem('q:jobs:' . $this->injectors['type'] . ':' . $this->injectors['state'], $this->injectors['id']);
        return $this;
    }

    public function state($state)
    {
        $this->removeState();
        $this->set('state', $state);
        $this->client->zadd('q:jobs', $this->injectors['priority'], $this->injectors['id']);
        $this->client->zadd('q:jobs:' . $state, $this->injectors['priority'], $this->injectors['id']);
        $this->client->zadd('q:jobs:' . $this->injectors['type'] . ':' . $state, $this->injectors['priority'], $this->injectors['id']);
        if ('inactive' == $state) $this->client->lpush('q:' . $this->injectors['type'] . ':jobs', 1);
        $this->set('updated_at', time());
        return $this;
    }

    public function update()
    {
        if (!$this->injectors['id']) return false;

        $this->injectors['updated_at'] = time();

        $job = $this->injectors;
        $job['data'] = json_encode($job['data']);
        $this->client->hmset('q:job:' . $this->injectors['id'], $job);
        $this->state($job['state']);

        return $this;
    }

    public function log($str)
    {
        $this->client->rpush('q:job:' . $this->injectors['id'] . ':log', $str);
        $this->set('updated_at', time());
        return $this;
    }

    public function get($key)
    {
        return $this->client->hget('q:job:' . $this->injectors['id'], $key);
    }

    public function set($key, $val)
    {
        $this->injectors[$key] = $val;
        $this->client->hset('q:job:' . $this->injectors['id'], $key, $val);
        return $this;
    }

    public function save()
    {
        $this->update();

        $this->client->sadd('q:job:types', $this->injectors['type']);

        return $this;
    }
}