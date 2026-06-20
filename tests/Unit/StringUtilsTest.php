<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use ZealPHP\StringUtils;
use ZealPHP\Tests\TestCase;

class StringUtilsTest extends TestCase
{
    public function testStrStartsWith(): void
    {
        $this->assertTrue(StringUtils::str_starts_with('hello world', 'hello'));
        $this->assertFalse(StringUtils::str_starts_with('hello world', 'world'));
        $this->assertTrue(StringUtils::str_starts_with('abc', ''));
        $this->assertFalse(StringUtils::str_starts_with('', 'x'));
    }

    public function testStrEndsWith(): void
    {
        $this->assertTrue(StringUtils::str_ends_with('hello world', 'world'));
        $this->assertFalse(StringUtils::str_ends_with('hello world', 'hello'));
        $this->assertTrue(StringUtils::str_ends_with('abc', ''));
        $this->assertFalse(StringUtils::str_ends_with('', 'x'));
    }

    public function testStrContains(): void
    {
        $this->assertTrue(StringUtils::str_contains('hello world', 'lo wo'));
        $this->assertFalse(StringUtils::str_contains('hello world', 'xyz'));
        $this->assertTrue(StringUtils::str_contains('abc', ''));
    }

    public function testGetStringBetween(): void
    {
        $this->assertSame('bar', StringUtils::get_string_between('foo[bar]baz', '[', ']'));
        // No start delimiter → empty string.
        $this->assertSame('', StringUtils::get_string_between('plain', '[', ']'));
        $this->assertSame('x', StringUtils::get_string_between('a<x>b', '<', '>'));
    }

    public function testGetStringBetweenMissingEnd(): void
    {
        // #448 — start present, end absent: return '' (no complete "between"
        // match), not the corrupt offset-dependent partial slice the old
        // unguarded `false - $ini` negative-length substr() produced.
        $this->assertSame('', StringUtils::get_string_between('foo[bar', '[', ']'));
        $this->assertSame('', StringUtils::get_string_between('abcdefghij', 'c', 'Z'));
        $this->assertSame('', StringUtils::get_string_between('hello-world-here', 'hello-', '##'));
        // A present end delimiter still slices correctly (no regression).
        $this->assertSame('bar', StringUtils::get_string_between('foo[bar]baz', '[', ']'));
    }
}
