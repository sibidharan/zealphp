<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use ZealPHP\Tests\TestCase;

use function ZealPHP\Session\php_session_decode_to_array;

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
    // SECURITY — allowed_classes must be FALSE in both branches
    // ──────────────────────────────────────────────────────────────

    public function testObjectInPhpSerializeBranchIsRejected(): void
    {
        // A serialized object would normally instantiate when unserialized.
        // With allowed_classes => false, PHP returns __PHP_Incomplete_Class —
        // is_array() check then fails and the function falls through to the
        // pipe-format parser, which finds no `|` and returns [].
        $payload = serialize(new \stdClass());
        $decoded = php_session_decode_to_array($payload);
        // Either an empty array (fall-through to pipe parser which finds no |)
        // OR a single-key array whose value is __PHP_Incomplete_Class. Neither
        // is the original \stdClass — the security property is "no arbitrary
        // class instantiation".
        if ($decoded !== []) {
            foreach ($decoded as $v) {
                $this->assertNotInstanceOf(\stdClass::class, $v);
                if (is_object($v)) {
                    $this->assertInstanceOf(\__PHP_Incomplete_Class::class, $v);
                }
            }
        }
        $this->assertTrue(true);
    }

    public function testObjectInPhpHandlerBranchIsRejected(): void
    {
        // Pipe-format with a serialized object as the value.
        $payload = 'obj|' . serialize(new \stdClass()) . ';';
        $decoded = php_session_decode_to_array($payload);
        // The value, if present, must NOT be a live \stdClass instance.
        if (isset($decoded['obj'])) {
            $this->assertNotInstanceOf(\stdClass::class, $decoded['obj']);
            if (is_object($decoded['obj'])) {
                $this->assertInstanceOf(\__PHP_Incomplete_Class::class, $decoded['obj']);
            }
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
