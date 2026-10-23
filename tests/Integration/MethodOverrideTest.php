<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Integration;

use ZealPHP\Tests\TestCase;

/**
 * X-HTTP-Method-Override on a POST is honoured only for PUT, PATCH and DELETE.
 * Any other value used to become the request method verbatim.
 */
final class MethodOverrideTest extends TestCase
{
    /** @param array<string, string> $headers */
    private function methodSeen(array $headers): ?string
    {
        $r = $this->post('/superglobals_basic.php', $headers, '');
        if ($r['status'] === 0) {
            $this->markTestSkipped('test server not running');
        }
        $json = is_array($r['json']) ? $r['json'] : [];
        return isset($json['METHOD']) && is_string($json['METHOD']) ? $json['METHOD'] : null;
    }

    public function testPutPatchDeleteOverridesAreHonoured(): void
    {
        $this->assertSame('PUT', $this->methodSeen(['X-HTTP-Method-Override' => 'PUT']));
        $this->assertSame('DELETE', $this->methodSeen(['X-HTTP-Method-Override' => 'delete']));
        $this->assertSame('PATCH', $this->methodSeen(['X-HTTP-Method-Override' => 'PATCH']));
    }

    public function testOtherOverridesAreIgnored(): void
    {
        foreach (['GET', 'TRACE', 'OPTIONS', 'PROPFIND', 'X'] as $verb) {
            $this->assertSame('POST', $this->methodSeen(['X-HTTP-Method-Override' => $verb]), $verb);
        }
    }
}
