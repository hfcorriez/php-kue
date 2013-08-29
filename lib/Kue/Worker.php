<?php

namespace Kue;

use Pagon\EventEmitter;

class Worker extends EventEmitter
{
    /**
     * @var Kue
     */
    protected $queue;

    /**
     * @var string
     */
    protected $type;

    /**
     * @var \Redis
     */
    protected $client;

    /**
     * @var int
     */
    protected $interval = 1;

    /**
     * Create worker
     *
     * @param Kue    $queue
     * @param string $type
     */
    public function __construct($queue, $type = null)
    {
        $this->queue = $queue;
        $this->type = $type;
        $this->client = $queue->client;
    }

    /**
     * Start worker
     */
    public function start()
    {
        while (1) {
            $job = $this->getJob();
            if ($job) {
                $this->process($job);
            }
            sleep($this->interval);
        }
    }

    /**
     * Process the job
     *
     * @param Job $job
     */
    public function process(Job $job)
    {
        $job->active();
        try {
            $start = time();
            $this->queue->emit('process:' . $job->type, $job);
            $duration = time() - $start;
            $job->set('duration', $duration);
        } catch (\Exception $e) {
            $this->failed($job, $e);
            return;
        }
        return;
    }

    /**
     * Failed trigger
     *
     * @param Job               $job
     * @param string|\Exception $error
     */
    public function failed(Job $job, $error)
    {
        $job->error($error);
        $job->attempt(function ($remaining) use ($job) {
            $remaining ? $job->inactive() : $job->failed();
        });
    }

    /**
     * Pop a job
     *
     * @param string $key
     * @return mixed
     */
    public function pop($key)
    {
        $this->client->multi();

        $this->client->zrange($key, 0, 0);
        $this->client->zremrangebyrank($key, 0, 0);

        $result = $this->client->exec();

        return $result[0][0];
    }

    /**
     * Get job
     *
     * @return bool|Job
     */
    public function getJob()
    {
        // Support no type worker
        if (!$this->type) {
            if (!$types = $this->queue->types()) return false;

            foreach ($types as $i => $type) {
                $types[$i] = 'q:' . $type . ':jobs';
            }
        } else {
            $types = 'q:' . $this->type . ':jobs';
        }

        try {
            if (!$ret = $this->client->blpop($types, 5)) return false;
        } catch (\Exception $e) {
            return false;
        }

        $arr = explode(':', $ret[0]);

        if (!$id = $this->pop('q:jobs:' . $arr[1] . ':inactive')) {
            return false;
        }

        return Job::load($id);
    }
}