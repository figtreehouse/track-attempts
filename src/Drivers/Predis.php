<?php

namespace Machaven\TrackAttempts\Drivers;

use Dotenv\Dotenv;
use Machaven\TrackAttempts\TrackAttemptsInterface;
use Machaven\TrackAttempts\Traits\CommonTrait;
use Machaven\TrackAttempts\Traits\ConfigTrait;
use Predis\Client as PredisClient;

class Predis implements TrackAttemptsInterface
{
    use ConfigTrait, CommonTrait;

    /**
     * @var \Predis\Client;
     */
    private $redis;

    public function __construct(array $config)
    {
        $this->config($config);
        $this->setup();
    }

    private function setup()
    {
        $dotenv = new Dotenv(__DIR__ . '/../../../../../');
        $dotenv->load();

        $this->redis = new PredisClient([
            'scheme' => getenv('REDIS_SCHEME'),
            'host' => getenv('REDIS_HOST'),
            'port' => getenv('REDIS_PORT'),
        ], [
            'parameters' => [
                'database' => getenv('REDIS_DB'),
            ],
            'profile' => getenv('REDIS_PROFILE'),
        ]);
    }

    private function getAttemptObject()
    {
        return unserialize($this->redis->get($this->redisKey));
    }

    private function keyExists()
    {
        return $this->redis->exists($this->redisKey);
    }

    public function increment()
    {
        $this->invalidateIfExpired();

        if ($this->isLimitReached()) {
            return false;
        }

        if ($this->keyExists()) {
            $attemptObject = $this->getAttemptObject();
            $attemptObject->attempts++;
            $this->redis->set($this->redisKey, serialize($attemptObject));
        } else {
            $expireSeconds = $this->ttlInMinutes * 60;
            $attemptObject = $this->createAttemptObject(1, $expireSeconds);

            $this->redis->set($this->redisKey, serialize($attemptObject));
            $this->redis->expire($this->redisKey, $expireSeconds);
        }

        return true;
    }

    public function getCount()
    {
        $this->invalidateIfExpired();

        if ($this->keyExists()) {
            return $this->getAttemptObject()->attempts;
        }

        return 0;
    }

    public function isLimitReached()
    {
        $this->invalidateIfExpired();

        if ($this->keyExists()) {

            if ($this->getCount() >= $this->maxAttempts) {
                return true;
            }
        }

        return false;
    }

    public function getTimeUntilExpired()
    {
        $this->invalidateIfExpired();

        if ($this->keyExists()) {
            return $this->getTimeUntilExpireCalculation($this->getAttemptObject()->expires);
        }

        return 0;
    }

    public function clear()
    {
        return (bool)$this->redis->del([$this->redisKey]);
    }
}