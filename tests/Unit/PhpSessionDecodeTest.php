<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use ZealPHP\Tests\TestCase;

use function ZealPHP\Session\php_session_decode_to_array;

/**
 * A custom class used by the security tests. NOT on the unserialize
 * whitelist — instantiation from session storage must be refused so a
 * tampered cookie / compromised Redis blob can't trigger gadget chains
 * (the c43da63 / issue #15 design constraint). Promoted to a real named
 * class so PHPStan can see the property without dynamic-property warnings.
 */
final class PhpSessionDecodeTestNonWhitelistedFake
{
    public string $payload = '';
}

/**
 * Unit tests for ZealPHP\Session\php_session_decode_to_array().
 *
 * The function decodes two session-handler wire formats:
 *   - `php_serialize` handler  → top-level serialize() of an array (a:N:{...})
 *   - `php` handler (default)  → custom `key|<serialized_value>;key|...` format
 *
 * SECURITY contract: both unserialize() calls must pass
 * `['allowed_classes' => false]` — sessions are user-controlled storage, and
 * allowing arbitrary class instantiation would re-introduce the PHP
 * object-injection vector that commit c43da63 closed.
 */
class PhpSessionDecodeTest extends TestCase
{
    // ──────────────────────────────────────────────────────────────
    // php_serialize handler format — top-level serialize() of an array
    // ──────────────────────────────────────────────────────────────

    public function testPhpSerializeFormatBasicArray(): void
    {
        $original = ['user_id' => 42, 'name' => 'alice'];
        $decoded  = php_session_decode_to_array(serialize($original));
        $this->assertSame($original, $decoded);
    }

    public function testPhpSerializeFormatNestedArray(): void
    {
        $original = ['user' => ['id' => 42, 'roles' => ['admin', 'editor']]];
        $decoded  = php_session_decode_to_array(serialize($original));
        $this->assertSame($original, $decoded);
    }

    public function testPhpSerializeFormatMixedScalars(): void
    {
        $original = ['int' => 1, 'float' => 1.5, 'bool' => true, 'null' => null, 'string' => 'hi'];
        $decoded  = php_session_decode_to_array(serialize($original));
        $this->assertSame($original, $decoded);
    }

    public function testNonStringKeysDropped(): void
    {
        // serialize() of array with int keys round-trips; the function narrows
        // to <string,mixed> by skipping non-string keys.
        $payload = serialize([0 => 'first', 1 => 'second', 'named' => 'third']);
        $decoded = php_session_decode_to_array($payload);
        $this->assertSame(['named' => 'third'], $decoded);
    }

    // ──────────────────────────────────────────────────────────────
    // php handler format — key|<serialized_value>;key|<serialized_value>;
    // ──────────────────────────────────────────────────────────────

    public function testPhpHandlerFormatBasicScalars(): void
    {
        // Manually constructed in PHP's native session-encode format
        $payload = 'user_id|i:42;name|s:5:"alice";';
        $decoded = php_session_decode_to_array($payload);
        $this->assertSame(['user_id' => 42, 'name' => 'alice'], $decoded);
    }

    public function testPhpHandlerFormatBooleanFalseEdgeCase(): void
    {
        // unserialize('b:0;') returns false — the decoder has a special case
        // for this to not confuse it with "unserialize failed".
        $payload = 'flag|b:0;active|b:1;';
        $decoded = php_session_decode_to_array($payload);
        $this->assertSame(['flag' => false, 'active' => true], $decoded);
    }

    public function testPhpHandlerFormatNestedArray(): void
    {
        $value   = ['id' => 7, 'roles' => ['admin']];
        $payload = 'user|' . serialize($value) . ';';
        $decoded = php_session_decode_to_array($payload);
        $this->assertSame(['user' => $value], $decoded);
    }

    public function testEmptyInputReturnsEmptyArray(): void
    {
        $this->assertSame([], php_session_decode_to_array(''));
    }

    // ──────────────────────────────────────────────────────────────
    // SECURITY — explicit class whitelist (stdClass only as of v0.2.26)
    // ──────────────────────────────────────────────────────────────

    public function testStdClassRoundTripsInPhpSerializeBranch(): void
    {
        // json_decode($x) without the second arg returns a stdClass graph,
        // which apps routinely stash in $_SESSION (OAuth token responses,
        // API profile payloads, etc.). v0.2.25's blanket allowed_classes
        // => false converted these to __PHP_Incomplete_Class on read,
        // breaking property access. v0.2.26 issue #15 narrowly whitelists
        // stdClass (zero methods, no gadget surface) so this round-trips.
        $token = new \stdClass();
        $token->access_token  = 'gho_xyz';
        $token->token_type    = 'bearer';
        $token->expires_in    = 3600;
        $payload = serialize(['oauth_token' => $token]);

        $decoded = php_session_decode_to_array($payload);
        $this->assertArrayHasKey('oauth_token', $decoded);
        $this->assertInstanceOf(\stdClass::class, $decoded['oauth_token'],
            'stdClass must round-trip as a live stdClass, not __PHP_Incomplete_Class');
        $this->assertSame('gho_xyz', $decoded['oauth_token']->access_token);
        $this->assertSame('bearer',  $decoded['oauth_token']->token_type);
        $this->assertSame(3600,      $decoded['oauth_token']->expires_in);
    }

    public function testStdClassRoundTripsInPhpHandlerBranch(): void
    {
        $profile = new \stdClass();
        $profile->id   = 42;
        $profile->name = 'jane';
        $payload = 'profile|' . serialize($profile) . ';';

        $decoded = php_session_decode_to_array($payload);
        $this->assertArrayHasKey('profile', $decoded);
        $this->assertInstanceOf(\stdClass::class, $decoded['profile']);
        $this->assertSame(42, $decoded['profile']->id);
        $this->assertSame('jane', $decoded['profile']->name);
    }

    public function testNonWhitelistedClassIsBlockedInPhpSerializeBranch(): void
    {
        // Custom user class — NOT on the whitelist. The point of whitelisting
        // stdClass narrowly is that arbitrary classes (which might have
        // __wakeup/__destruct gadgets) still can't be instantiated from
        // session storage. This payload must NOT come back as a live
        // PhpSessionDecodeTestNonWhitelistedFake instance.
        $obj = new PhpSessionDecodeTestNonWhitelistedFake();
        $obj->payload = 'pretend this triggers a gadget';
        $payload = serialize(['malicious' => $obj]);

        $decoded = php_session_decode_to_array($payload);
        // The 'malicious' key might be present (as __PHP_Incomplete_Class)
        // OR the whole decode might have failed and fallen through to the
        // pipe parser which returns [] — either is acceptable. The
        // invariant we pin: NO live instance of the custom class.
        if (isset($decoded['malicious'])) {
            $this->assertNotInstanceOf(
                PhpSessionDecodeTestNonWhitelistedFake::class,
                $decoded['malicious']
            );
        }
        $this->assertTrue(true);
    }

    public function testNonWhitelistedClassIsBlockedInPhpHandlerBranch(): void
    {
        $obj = new PhpSessionDecodeTestNonWhitelistedFake();
        $obj->payload = 'pretend this triggers a gadget';
        $payload = 'malicious|' . serialize($obj) . ';';

        $decoded = php_session_decode_to_array($payload);
        if (isset($decoded['malicious'])) {
            $this->assertNotInstanceOf(
                PhpSessionDecodeTestNonWhitelistedFake::class,
                $decoded['malicious']
            );
        }
        $this->assertTrue(true);
    }

    public function testMalformedDataDoesNotCrash(): void
    {
        // Garbage bytes — should not throw, just return [] or skip past the
        // bad entries.
        $payloads = [
            'this is not a valid session string',
            'key|garbage;key2|i:1;',
            "\x00\x01\x02bin garbage",
        ];
        foreach ($payloads as $p) {
            $decoded = php_session_decode_to_array($p);
            $this->assertIsArray($decoded, "malformed payload caused non-array return: " . bin2hex(substr($p, 0, 8)));
        }
    }
}
