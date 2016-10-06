<?php
/**
 * Job.php.
 */

namespace Kue;

use Pagon\Fiber;

class Job extends Fiber
{
    protected static $PRIORITIES = [
        'low'      => 10,
        'normal'   => 0,
        'medium'   => -5,
        'high'     => -10,
        'critical' => -15
    ];

    protected $injectors = [
        'type'         => null,
        'data'         => [],
        'priority'     => 0,
        'progress'     => 0,
        'state'        => 'inactive',
        'error'        => '',
        'created_at'   => '',
        'updated_at'   => '',
        'failed_at'    => '',
        'duration'     => 0,
        'timing'       => 0,
        'attempts'     => 0,
        'max_attempts' => 1
    ];

    /**
     * @var int
     */
    protected $id;

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

        if (!$data = $client->hgetall($this->queue->getKey('job:' . $id))) {
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
    public function __construct($type, $data = [])
    {
        $this->injectors['type'] = $type;
        $this->injectors['data'] = $data;
        $this->injectors['created_at'] = Util::now();

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
        $max = $this->get('max_attempts');
        $attempts = $this->client->hincrby($this->queue->getKey('job:' . $this->id), 'attempts', 1);
        $fn(max(0, $max - $attempts + 1), $attempts - 1, $max);
    }

    /**
     * Set max attempts
     *
     * @param int $num
     */
    public function attempts($num)
    {
        $plus = $num - $this->injectors['max_attempts'];
        $this->injectors['max_attempts'] += $plus;
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

        if (!$this->queue->originalMode()) {
            $this->injectors['timing'] = $time * 1000;
            $this->injectors['state'] = 'inactive';
        } else {
            $this->delay($time - time());
        }
    }

    /**
     * Set job delay
     *
     * @param int $s    Delay time in seconds
     * @return $this
     */
    public function delay($s)
    {
        if (!$this->queue->originalMode()) {
            $this->timing(time() + $s);
        } else {
            $this->injectors['delay'] = $s * 1000;
            $this->injectors['state'] = 'delayed';
        }
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
        $this->set('updated_at', Util::now());
    }

    /**
     * Set error
     *
     * @param string $error
     * @return $this
     */
    public function error($error = null)
    {
        if ($error === null) return $this->injectors['error'];

        $this->emit('error', $error);

        if ($error instanceof \Exception) {
            $str = get_class($error) . ' Error on ' . $error->getFile() . ' ' . $error->getLine();
            $str .= $error->getTraceAsString();
        } else {
            $str = $error;
        }

        $this->set('error', $str);
        $this->set('failed_at', Util::now());
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
        $this->client->zrem($this->queue->getKey('jobs'), $this->id);
        $this->client->zrem($this->queue->getKey('jobs:' . $this->injectors['state']), $this->id);
        $this->client->zrem($this->queue->getKey('jobs:' . $this->injectors['type'] . ':' . $this->injectors['state']), $this->id);
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

        // Keep "FIFO!"
        $score = $this->injectors['timing'] + $this->injectors['priority'];

        $this->set('state', $state);
        $this->client->zadd($this->queue->getKey('jobs'), $score, $this->id);
        $this->client->zadd($this->queue->getKey('jobs:' . $state), $score, $this->id);
        $this->client->zadd($this->queue->getKey('jobs:' . $this->injectors['type'] . ':' . $state), $score, $this->id);

        // Set inactive job to waiting list
        if ($this->queue->originalMode() && 'inactive' == $state) $this->client->lpush($this->queue->getKey($this->injectors['type'] . ':jobs'), 1);

        $this->set('updated_at', Util::now());
        return $this;
    }

    /**
     * Update the job
     *
     * @return $this|bool
     */
    public function update()
    {
        if (empty($this->id)) return false;

        $this->emit('update');
        $this->injectors['updated_at'] = Util::now();

        $job = $this->injectors;
        $job['data'] = json_encode($job['data']);
        $this->client->hmset($this->queue->getKey('job:' . $this->id), $job);
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
        $this->client->rpush($this->queue->getKey('job:' . $this->id . ':log'), $str);
        $this->set('updated_at', Util::now());
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
        return $this->client->hget($this->queue->getKey('job:' . $this->id), $key);
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
        $this->client->hset($this->queue->getKey('job:' . $this->id), $key, $val);
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

        if (empty($this->id)) {
            $this->id = $this->client->incr($this->queue->getKey('ids'));
        }

        $this->update();

        $this->client->sadd($this->queue->getKey('job:types'), $this->injectors['type']);

        return $this;
    }
}
