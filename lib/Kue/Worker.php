<?php

namespace Kue;

use Pagon\EventEmitter;

class Worker extends EventEmitter
{
    protected $queue;

    protected $type;

    protected $client;

    protected $interval = 1;

    public function __construct($queue, $type)
    {
        $this->queue = $queue;
        $this->type = $type;
        $this->client = $queue->client;
    }

    public function start($fn)
    {
        while (1) {
            $job = $this->getJob();
            if ($job) {
                $this->process($job, $fn);
            }
            sleep($this->interval);
        }
    }

    /**
     * Process the job
     *
     * @param Job      $job
     * @param \Closure $fn
     */
    public function process(Job $job, $fn)
    {
        $job->active();
        try {
            $start = time();
            $fn($job);
            $duration = time() - $start;
            $job->set('duration', $duration);
        } catch (\Exception $e) {
            $this->failed($job, $e, $fn);
            return;
        }
        return;
    }

    public function failed(Job $job, $error, $fn)
    {
        $job->error($error);
        $that = $this;
        $job->attempt(function ($remaining) use ($job, $that, $fn) {
            $remaining ? $job->inactive() : $job->failed();
            $that->start($fn);
        });
    }

    public function pop($key)
    {
        $this->client->multi();

        $this->client->zrange($key, 0, 0);
        $this->client->zremrangebyrank($key, 0, 0);

        $result = $this->client->exec();

        return $result[0][0];
    }

    public function getJob()
    {
        try {
            $this->client->blpop('q:' . $this->type . ':jobs', 0);
        } catch (\Exception $e) {
            return false;
        }

        if (!$id = $this->pop('q:jobs:' . $this->type . ':inactive')) {
            return false;
        }

        return Job::load($id);
    }
}