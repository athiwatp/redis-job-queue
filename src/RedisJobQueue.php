<?php

/**
 * This file is distributed under the MIT Open Source
 * License. See README.MD for details.
 * @author Ioan CHIRIAC
 */


// require dependencies
require_once __DIR__ . '/RedisClient.php';
require_once __DIR__ . '/JobManager.php';
require_once __DIR__ . '/JobWorker.php';

/**
 * Main script body
 */
class RedisJobQueue {
    // the current hostname
    public $host;
    // configuration
    public $conf;
    // running flag on main loop
    public $run = true;
    // the current process id
    public $pid = -1;
    // statistics
    public $stats = array(
        'init' => null,
        'start' => null,
        'memory' => null,
        'counters' => array(
            'workers' => 0,
            'queue' => 0,
            'done' => 0,
            'fail' => 0,
            'errors' => 0
        )
    );
    // instance of redis connection
    private $redis;
    // list of job dispatchers
    private $jobs = array();
    // gets the last flush timestamp
    private $last_flush = null;
    // initialize the job manager
    public function __construct( array $conf ) {
        $this->conf = $conf;
        if ( empty($this->conf['server']) ) {
            throw new \Exception(
                'FATAL ERROR : Bad configuration structure'
            );
        }
        $this->host = php_uname('n');
        if ( function_exists('posix_getpid') ) {
            $this->pid = posix_getpid();
        }
        $this->stats['init'] = time();
        foreach( $this->conf['jobs'] as $task ) {
            $name = strtolower($task['name']);
            $this->jobs[$name] = new JobManager(
                $this,
                $name,
                $task['file'],
                $task['workers']
            );
        }
    }
    /**
     * Gets the redis connection
     * @return RedisClient
     */
    public function getRedis() {
        if ( !$this->redis ) {
            try {
                $sender = $this;
                $this->redis = new RedisClient(
                    $this->conf['server']['dsn'],
                    $this->conf['server']['db'],
                    $this->conf['server']['pwd']
                );
                $this->redis->onError = function($error) use($sender) {
                    $this->log('Redis error : ' . $error);
                    $sender->redis = null;
                    // wait 1sec before retry
                    $sender->wait(1000);
                };
                $this->redis->connect();
            } catch(\Exception $ex) {
                $this->redis = null;
                $this->log('Fail to load redis : ' . $this->conf['server']['dsn']);
                $this->stats['counters']['errors'] ++;
                return null;
            }
        }
        return $this->redis;
    }
    // outputs some log
    public function log( $data ) {
        echo $data . "\n";
        if (!empty($this->conf['log']) ) {
            $f = fopen( $this->conf['log'], 'a+');
            if ( $f ) {
                fputs($f, date('Y-m-d H:i:s') . "\t" . $data . "\n");
                fclose($f);
            }
        }
    }
    // make a pause and wait
    public function wait($msec = 0) {
        if ( $this->pid > 0 ) pcntl_signal_dispatch();
        if ( !empty($msec) ) {
            usleep($msec * 1000);
        }
        if ( time() > $this->last_flush + 10) {
            // flushing and do extra jobs every 10 seconds
            $this->last_flush = time();
            gc_collect_cycles();
            $this->stats['memory'] = memory_get_usage(true);
            // write stats as a file
            if ( !empty($this->conf['stats']) ) {
                file_put_contents(
                    $this->conf['stats'], json_encode(
                        $this->stats
                    )
                );
            } else {
                print_r($this->stats); // helper for the cli mode
            }
            // send stats to redis
            $redis = $this->getRedis();
            if ( $redis ) {
                try {
                    $redis->hmset(
                        'rjq.stats.' . $this->host,
                        array(
                            'status' => 'run',
                            'memory' => $this->stats['memory'],
                            'nb.workers' => $this->stats['counters']['workers'],
                            'nb.done' => $this->stats['counters']['done'],
                            'nb.fail' => $this->stats['counters']['fail'],
                            'nb.errors' => $this->stats['counters']['errors']
                        )
                    )->read();
                } catch(\Exception $ex) {
                    $this->log(
                        'Fail to flush stats on redis :' . $ex->getMessage()
                    );
                }
            }
        }
    }
    // starts the job
    public function start() {
        $this->log('Starting RJQ (PID:' . $this->pid . ')');
        $this->stats['start'] = time();
        // the main loop
        while($this->run) {
            $work = false;
            foreach($this->jobs as $job) {
                try {
                    if ($job->dispatch()) {
                        $work = true;
                    }
                } catch(\Exception $ex) {
                    $this->log(
                        'Job manager error : ' . $ex->getMessage()
                    );
                    if ( VERBOSE ) {
                        $this->log($ex->__toString());
                    }
                    $this->stats['counters']['errors'] ++;
                }
            }
            // wait 50ms :
            if ( !$work ) self::wait(50);
        }
        $this->log('LOOP END : Wait to close each job queue');
        // wait each child to be stopped
        while(!empty($this->jobs)) {
            foreach($this->jobs as $i => $job) {
                if ( $job->clean() ) {
                    $this->log('The queue "'.$job->prefix.'" is closed');
                    unset($this->jobs[$i]);
                }
            }
        }
    }
    // force to stop all workers in progress
    public function stop() {
        if ( !empty($this->jobs) ) {
            $this->log('Forcing to stop job queue :');
            foreach($this->jobs as $j => $job) {
                if ( !empty($job->workers) ) {
                    $busy = 0;
                    foreach($job->workers as $i => $w) {
                        if ( $w->busy ) {
                            $busy ++;
                        }
                        unset($job->workers[$i]);
                    }
                    if ( $busy > 0 ) {
                        $this->log(
                            'The queue "'.$job->prefix.'" is stopped with '
                            . $busy . ' pending workers'
                        );
                    } else {
                        $this->log('The queue "'.$job->prefix.'" is OK');
                    }
                } else {
                    $this->log('The queue "'.$job->prefix.'" is OK');
                }
                unset($this->jobs[$j]);
            }
        }
    }
}
