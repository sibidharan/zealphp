<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit\CGI;

use PHPUnit\Framework\TestCase;
use ZealPHP\CGI\SubprocessError;

/** A CGI subprocess's 500: details to the log, to the client only on request. */
class SubprocessErrorTest extends TestCase
{
    public function testBodyIsGenericUnlessDisplayIsOn(): void
    {
        $e = new \RuntimeException('db password is hunter2');
        $this->assertSame('500 Internal Server Error', SubprocessError::body($e, false));
        $shown = SubprocessError::body($e, true);
        $this->assertStringStartsWith("RuntimeException: db password is hunter2\n#0 ", $shown);
    }

    public function testOnlyALiteralTrueRequestsDetails(): void
    {
        $this->assertTrue(SubprocessError::displayRequested(['display_errors' => true]));
        foreach ([['display_errors' => 1], ['display_errors' => 'true'], ['display_errors' => false], [], null, 'x'] as $req) {
            $this->assertFalse(SubprocessError::displayRequested($req), var_export($req, true));
        }
    }

    public function testLogWritesTheDetailsToTheStream(): void
    {
        $stream = fopen('php://memory', 'w+');
        $this->assertIsResource($stream);
        $e = new \LogicException('boom');
        SubprocessError::log($e, 'pool_worker', $stream);
        rewind($stream);
        $line = (string) stream_get_contents($stream);
        $this->assertStringStartsWith('[pool_worker] LogicException: boom in ' . __FILE__ . ':' . $e->getLine() . "\n#0 ", $line);
        $this->assertStringEndsWith("\n", $line);
    }
}
