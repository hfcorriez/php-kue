# Intro

PHP port of [Kue](https://github.com/LearnBoost/kue/) (Node.js)

Note: `It's under develop now!`

The goal of php-kue is: `Supply a simple and strong way to process the background jobs using PHP`

# Install

Add `"kue/kue": "*"` to your composer.json, then using following command install all dependencies.

```
composer install
```

# Overview

- [Create Queue](#create-queue)
-- [Create with config](#create-with-redis-config)
- [Node Compatible mode](#node-compatible-mode)
- [Create job](#create-job)
-- [Create priority job](#create-job-with-priority)
-- [Create timing job](#create-job-with-timing)
-- [Create delayed job](#create-job-with-delay-time)
-- [Create attempts job](#create-job-with-attempts)
- [Process job](#process-job)
-- [Process with type](#Process-given-type)
-- [Process all](#Process-all-types)

# Usage

## Create Queue

```php
// Connect to redis "localhost:6379"
$kue = Kue::createQueue();
```

### Create with redis config

```php
// Connect "redis_server:6379" and select db to "1"
$kue = Kue::createQueue(array('host' => 'redis_server', 'db' => 1));
```

## Node Compatible mode

In this mode, you can create job using `PHP`, and process job with `Node.js`

```php
$kue = Kue::createQueue();

$kue->originalMode(true);
```

> The original mode will create job structure same as `Node.js`, The default mode change a little structure because of some reasons for simply using in PHP.

## Create Job

```php
$kue = Kue::createQueue();

$kue->create('email', array(
    'to' => 'hfcorriez@gmail.com',
    'subject' => 'Reset your password!',
    'body' => 'Your can reset your password in 5 minutes. Url: http://xxx/reset'
))->save();
```

### Create job with priority

Priority will decide your job process order:

```php
$kue = Kue::createQueue();

$kue->create('email', array(
    'to' => 'hfcorriez@gmail.com',
    'subject' => 'Reset your password!',
    'body' => 'Your can reset your password in 5 minutes. Url: http://xxx/reset'
))->priority('high')->save();
```

###　Create job with timing

Timing will trigger job at given time, see follow examples:

```php
$kue = Kue::createQueue();

$kue->create('email', array(
    'to' => 'hfcorriez@gmail.com',
    'subject' => 'Reset your password!',
    'body' => 'Your can reset your password in 5 minutes. Url: http://xxx/reset'
))->timing('tomorrow')->save();
```

Timing format is process as [PHP date and time formats](http://php.net/manual/en/datetime.formats.php), You can use:

- `Next Monday`
- `+1 days`
- `last day of next month`
- `2013-09-13 00:00:00`
- and so on..

### Create Job with delay time

The follow example will delay job in 3600 seconds:

```php
$kue = Kue::createQueue();

$kue->create('email', array(
    'to' => 'hfcorriez@gmail.com',
    'subject' => 'Reset your password!',
    'body' => 'Your can reset your password in 5 minutes. Url: http://xxx/reset'
))->delay(3600)->save();
```

###　Crete job with attempts

When the job failed, the next example will show how to attempts:

```php
$kue = Kue::createQueue();

$kue->create('email', array(
    'to' => 'hfcorriez@gmail.com',
    'subject' => 'Reset your password!',
    'body' => 'Your can reset your password in 5 minutes. Url: http://xxx/reset'
))->attempts(5)->save();
```

## Process job

`Note: $kue->process is blocking`

To process the jobs, you must write a script and run as daemon:

```php
$kue = Kue::createQueue();

// Process the `email` type job
$kue->on('process:email', function($job){
    // Process logic
    $data = $job->data
    mail($data['to'], $data['subject'], $data['body']);
});

// Will blocking process to subscribe the queue
$kue->process();
```

> I'll supply the daemon script and service in future.

### Process given type

If your want to write a script to process the given type.

```php
$kue = Kue::createQueue();

// Process the `email` type job
$kue->on('process:email', function($job){
    // Process logic
    $data = $job->data
    mail($data['to'], $data['subject'], $data['body']);
});

// Process `email` type only
$kue->process('email');
```

or using `Node.js` style

```php
$kue = Kue::createQueue();

// Process `email` type only
$kue->process('email', function($job){
   // Process logic
   $data = $job->data
   mail($data['to'], $data['subject'], $data['body']);
});
```

### Process all types

```php
$kue = Kue::createQueue();

// Process all types
$kue->process(function($job){
   // Process logic
   log($job->type . ' processed');
});
```

# License

(The MIT License)

Copyright (c) 2012 hfcorriez &lt;hfcorriez@gmail.com&gt;

Permission is hereby granted, free of charge, to any person obtaining
a copy of this software and associated documentation files (the
'Software'), to deal in the Software without restriction, including
without limitation the rights to use, copy, modify, merge, publish,
distribute, sublicense, and/or sell copies of the Software, and to
permit persons to whom the Software is furnished to do so, subject to
the following conditions:

The above copyright notice and this permission notice shall be
included in all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED 'AS IS', WITHOUT WARRANTY OF ANY KIND,
EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT.
IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY
CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT,
TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE
SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.