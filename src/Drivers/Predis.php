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

        $hostConfig = [
            'scheme' => getenv('REDIS_SCHEME'),
            'host' => getenv('REDIS_HOST'),
            'port' => getenv('REDIS_PORT'),
        ];

        $password = getenv('REDIS_PASSWORD');

        if (!empty($password)) {
            $hostConfig['password'] = $password;
        }

        $this->redis = new PredisClient($hostConfig, [
            'parameters' => [
                'database' => getenv('REDIS_DB'),
            ],
            'profile' => getenv('REDIS_PROFILE'),
        ]);
    }

    private function getAttemptObject()
    {
        return unserialize($this->redis->get($this->trackingKey));
    }

    private function keyExists()
    {
        return $this->redis->exists($this->trackingKey);
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
            $expiresFromNow = $this->getTimeUntilExpireCalculation($attemptObject->expires);
            $this->redis->set($this->trackingKey, serialize($attemptObject));
            $this->redis->expire($this->trackingKey, $expiresFromNow);
        } else {
            $expireSeconds = $this->ttlInMinutes * 60;
            $attemptObject = $this->createAttemptObject(1, $expireSeconds);

            $this->redis->set($this->trackingKey, serialize($attemptObject));
            $this->redis->expire($this->trackingKey, $expireSeconds);
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
        return (bool)$this->redis->del([$this->trackingKey]);
    }
}