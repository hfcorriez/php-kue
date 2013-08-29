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
        'timing'             => 0,
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

    /**
     * Load job
     *
     * @param string $id
     * @return bool|Job
     */
    public static function load($id)
    {
        $client = Kue::$instance->client;

        if (!$data = $client->hgetall('q:job:' . $id)) {
            return false;
        }

        $data['data'] = json_decode($data['data'], true);
        $job = new self($data['type'], null);
        $job->append($data);

        return $job;
    }

    /**
     * Create job
     *
     * @param array $type
     * @param array $data
     * @return \Kue\Job
     */
    public function __construct($type, $data = array())
    {
        $this->injectors['id'] = sha1(uniqid());
        $this->injectors['type'] = $type;
        $this->injectors['data'] = $data;
        $this->injectors['created_at'] = time();

        $this->queue = Kue::$instance;
        $this->client = Kue::$instance->client;
    }

    /**
     * Set priority
     *
     * @param string|int $pri
     */
    public function priority($pri)
    {
        if (is_numeric($pri)) {
            $this->injectors['priority'] = $pri;
        } else if (isset(self::$PRIORITIES[$pri])) {
            $this->injectors['priority'] = self::$PRIORITIES[$pri];
        }
    }

    /**
     * Attempt by function
     *
     * @param $fn
     */
    public function attempt($fn)
    {
        $max = $this->get('attempts_max');
        $attempts = $this->client->hincrby('q:job:' . $this->injectors['id'], 'attempts', 1);
        $fn(max(0, $max - $attempts), $attempts, $max);
    }

    /**
     * Set max attempts
     *
     * @param int $num
     */
    public function attempts($num)
    {
        $plus = $num - $this->injectors['attempts_max'];
        $this->injectors['attempts_max'] += $plus;
        $this->injectors['attempts_remaining'] += $plus;
    }

    /**
     * Timing job
     *
     * @param int|string $time
     */
    public function timing($time)
    {
        if (!is_numeric($time)) {
            $time = strtotime($time);
        }

        $this->injectors['timing'] = $time * 1000;
        $this->injectors['state'] = 'inactive';
    }

    /**
     * Set job delay
     *
     * @param int $s
     * @return $this
     */
    public function delay($s)
    {
        $this->timing(time() + $s);
        return $this;
    }

    /**
     * Set progress
     *
     * @param int|float $pt
     */
    public function progress($pt)
    {
        $this->set('progress', min(100, ($pt < 1 ? $pt * 100 : $pt)));
        $this->set('updated_at', time());
    }

    /**
     * Set error
     *
     * @param string $error
     * @return $this
     */
    public function error($error)
    {
        if (!$error) return $this->injectors['error'];

        $this->emit('error', $error);

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

    /**
     * Set complete
     *
     * @return mixed
     */
    public function complete()
    {
        return $this->set('progress', 100)->state('complete');
    }

    /**
     * Set failed
     *
     * @return $this
     */
    public function failed()
    {
        return $this->state('failed');
    }

    /**
     * Set inactive
     *
     * @return $this
     */
    public function inactive()
    {
        return $this->state('inactive');
    }

    /**
     * Set active
     *
     * @return $this
     */
    public function active()
    {
        return $this->state('active');
    }

    /**
     * Remove all state from sorted sets
     *
     * @return $this
     */
    public function removeState()
    {
        $this->client->zrem('q:jobs', $this->injectors['id']);
        $this->client->zrem('q:jobs:' . $this->injectors['state'], $this->injectors['id']);
        $this->client->zrem('q:jobs:' . $this->injectors['type'] . ':' . $this->injectors['state'], $this->injectors['id']);
        return $this;
    }

    /**
     * Change state
     *
     * @param $state
     * @return $this
     */
    public function state($state)
    {
        $this->emit($state);
        $this->removeState();
        $score = $this->injectors['timing'] + $this->injectors['priority'];
        $this->set('state', $state);
        $this->client->zadd('q:jobs', $score, $this->injectors['id']);
        $this->client->zadd('q:jobs:' . $state, $score, $this->injectors['id']);
        $this->client->zadd('q:jobs:' . $this->injectors['type'] . ':' . $state, $score, $this->injectors['id']);

        // Set inactive job to waiting list
        // if ('inactive' == $state) $this->client->lpush('q:' . $this->injectors['type'] . ':jobs', 1);

        $this->set('updated_at', time());
        return $this;
    }

    /**
     * Update the job
     *
     * @return $this|bool
     */
    public function update()
    {
        if (!$this->injectors['id']) return false;

        $this->emit('update');
        $this->injectors['updated_at'] = time();

        $job = $this->injectors;
        $job['data'] = json_encode($job['data']);
        $this->client->hmset('q:job:' . $this->injectors['id'], $job);
        $this->state($job['state']);

        return $this;
    }

    /**
     * Write job log
     *
     * @param string $str
     * @return $this
     */
    public function log($str)
    {
        $this->emit('log', $str);
        $this->client->rpush('q:job:' . $this->injectors['id'] . ':log', $str);
        $this->set('updated_at', time());
        return $this;
    }

    /**
     * Get job property
     *
     * @param string $key
     * @return mixed
     */
    public function get($key)
    {
        return $this->client->hget('q:job:' . $this->injectors['id'], $key);
    }

    /**
     * Set job property
     *
     * @param string $key
     * @param string $val
     * @return $this
     */
    public function set($key, $val)
    {
        $this->injectors[$key] = $val;
        $this->client->hset('q:job:' . $this->injectors['id'], $key, $val);
        return $this;
    }

    /**
     * Save the job
     *
     * @return $this
     */
    public function save()
    {
        $this->emit('save');
        $this->update();

        $this->client->sadd('q:job:types', $this->injectors['type']);

        return $this;
    }
}