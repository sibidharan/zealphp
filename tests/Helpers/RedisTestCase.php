<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Helpers;

use PHPUnit\Framework\TestCase;
use Predis\Client as PredisClient;

abstract class RedisTestCase extends TestCase
{
    protected ?PredisClient $client = null;
    protected string $url;

    protected function setUp(): void
    {
        $url = getenv('ZEALPHP_REDIS_URL');
        $this->url = is_string($url) && $url !== '' ? $url : 'redis://127.0.0.1:16379/0';
        try {
            $this->client = new PredisClient($this->url);
            $this->client->ping();
        } catch (\Throwable $e) {
            $this->client = null;
            $this->markTestSkipped('Redis/Valkey not available at ' . $this->url . ' (' . $e->getMessage() . ')');
        }
        $this->client->flushdb();
    }

    protected function tearDown(): void
    {
        if ($this->client !== null) {
            try { $this->client->flushdb(); } catch (\Throwable $e) {}
            try { $this->client->disconnect(); } catch (\Throwable $e) {}
        }
    }
}
