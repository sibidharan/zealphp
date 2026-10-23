<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use OpenSwoole\Coroutine;
use ZealPHP\App;
use ZealPHP\Tests\TestCase;

/**
 * App::inConnectionOrder() — pipelined HTTP/1.1 requests on one connection are
 * handled (and therefore answered) in request order; separate connections stay
 * concurrent.
 */
class AppConnectionOrderTest extends TestCase
{
    public function testSameConnectionRunsInArrivalOrder(): void
    {
        $done = [];
        Coroutine::run(function () use (&$done) {
            // Earlier requests sleep LONGER: completion order would be 2,1,0.
            foreach ([0, 1, 2] as $i) {
                go(function () use ($i, &$done) {
                    App::inConnectionOrder(7, function () use ($i, &$done) {
                        Coroutine::usleep((3 - $i) * 20000);
                        $done[] = $i;
                    });
                });
            }
        });
        $this->assertSame([0, 1, 2], $done);
    }

    public function testSeparateConnectionsDoNotWaitOnEachOther(): void
    {
        $done = [];
        Coroutine::run(function () use (&$done) {
            foreach ([0, 1, 2] as $i) {
                go(function () use ($i, &$done) {
                    App::inConnectionOrder(100 + $i, function () use ($i, &$done) {
                        Coroutine::usleep((3 - $i) * 20000);
                        $done[] = $i;
                    });
                });
            }
        });
        $this->assertSame([2, 1, 0], $done, 'different connections must stay concurrent');
    }

    public function testThrowingRequestStillHandsOverTheConnection(): void
    {
        $done = [];
        Coroutine::run(function () use (&$done) {
            go(function () {
                try {
                    App::inConnectionOrder(9, function () {
                        Coroutine::usleep(10000);
                        throw new \RuntimeException('boom');
                    });
                } catch (\RuntimeException) {
                }
            });
            go(function () use (&$done) {
                App::inConnectionOrder(9, function () use (&$done) {
                    $done[] = 'next';
                });
            });
        });
        $this->assertSame(['next'], $done);
    }

    public function testCloseReleasesQueuedRequests(): void
    {
        $done = [];
        Coroutine::run(function () use (&$done) {
            go(function () use (&$done) {
                App::inConnectionOrder(11, function () use (&$done) {
                    Coroutine::usleep(50000);
                    $done[] = 'first';
                });
            });
            go(function () use (&$done) {
                App::inConnectionOrder(11, function () use (&$done) {
                    $done[] = 'queued';
                });
            });
            go(function () {
                Coroutine::usleep(10000);
                App::releaseConnectionTurns(11);   // client hung up mid-pipeline
            });
        });
        $this->assertSame(['queued', 'first'], $done, 'a closed connection must not strand queued requests');
    }

    public function testPipelineBeyondTheCapIsRefused(): void
    {
        $ran = 0;
        $overflowed = 0;
        Coroutine::run(function () use (&$ran, &$overflowed) {
            // One slow request owns the connection; 10 more than the cap queue behind it.
            for ($i = 0; $i <= App::MAX_PIPELINED_REQUESTS + 10; $i++) {
                go(function () use ($i, &$ran, &$overflowed) {
                    App::inConnectionOrder(15, function () use ($i, &$ran) {
                        if ($i === 0) {
                            Coroutine::usleep(30000);
                        }
                        $ran++;
                    }, function () use (&$overflowed) {
                        $overflowed++;
                    });
                });
            }
        });
        $this->assertSame(App::MAX_PIPELINED_REQUESTS + 1, $ran, 'the running request plus a full queue are served');
        $this->assertSame(10, $overflowed, 'requests past the cap must be refused, not parked');
    }

    public function testOutsideCoroutineIsAPlainCall(): void
    {
        $ran = false;
        App::inConnectionOrder(13, function () use (&$ran) {
            $ran = true;
        });
        $this->assertTrue($ran);
    }
}
