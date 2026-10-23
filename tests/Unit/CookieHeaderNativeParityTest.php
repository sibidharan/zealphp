<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use ZealPHP\App;
use ZealPHP\Tests\TestCase;

/**
 * App::parseCookieHeader() against what PHP 8.3 itself puts in $_COOKIE for
 * the same Cookie header (each expectation was taken from `php -S` dumping
 * $_COOKIE). The parse_str()-based parser URL-decoded names — re-opening the
 * `__Host-`/`__Secure-` prefix bypass (CVE-2022-31629) — let the last
 * duplicate win and split names at `&`.
 */
final class CookieHeaderNativeParityTest extends TestCase
{
    /** @return array<string, array{0: string, 1: array<array-key, mixed>}> */
    public static function nativeCases(): array
    {
        return [
            'encoded __Host- name is not decoded'   => ['%5F%5FHost-sid=evil; __Host-sid=good', ['%5F%5FHost-sid' => 'evil', '__Host-sid' => 'good']],
            'partly encoded __Host- name'            => ['_%5FHost-sid=evil', ['_%5FHost-sid' => 'evil']],
            'encoded __Secure- name'                 => ['__Secure%2Dsid=evil', ['__Secure%2Dsid' => 'evil']],
            'first duplicate wins'                   => ['__Host-sid=good; __Host-sid=evil', ['__Host-sid' => 'good']],
            'first duplicate wins (plain)'           => ['a=1; a=2', ['a' => '1']],
            'mangled into __Host- is dropped'        => ['..Host-sid=evil', []],
            'mangled into __Host- after the real one' => ['__Host-x=1; ..Host-x=2', ['__Host-x' => '1']],
            'mangled into __Secure- array'           => ['..Secure-x[a]=1', []],
            'literal prefix keeps its mangling'      => ['__Host-a.b=1', ['__Host-a_b' => '1']],
            'not a prefix after mangling'            => ['__Host.sid=x', ['__Host_sid' => 'x']],
            'leading space then prefix'              => [' __Host-x=1', ['__Host-x' => '1']],
            'name with encoded ='                    => ['a%3Db=1', ['a%3Db' => '1']],
            'name with &'                            => ['a&b=1', ['a&b' => '1']],
            'name with +'                            => ['a+b=1', ['a+b' => '1']],
            'name with %20'                          => ['a%20b=1', ['a%20b' => '1']],
            'dot and leading space'                  => [' a.b=1', ['a_b' => '1']],
            'values rawurldecoded'                   => ['plus=a+b; pct=%41%2B', ['plus' => 'a+b', 'pct' => 'A+']],
            'arrays'                                 => ['arr[]=x; arr[]=y; map[k]=v; map[k]=w', ['arr' => ['x', 'y'], 'map' => ['k' => 'w']]],
            'array replaces scalar'                  => ['x=1; x[]=2', ['x' => ['2']]],
            'scalar does not replace array'          => ['x[]=1; x=2', ['x' => ['1']]],
            // PHP binds the bare `flag` as ''; ZealPHP keeps skipping it
            // (SuperglobalParityUnitTest::testCookieBareFlagSegmentSkipped).
            'value with =, empty name, bare flag'    => ['a=b=c; =novalue; flag; e=', ['a' => 'b=c', 'e' => '']],
            'unmatched ['                            => ['a[b=1', ['a_b' => '1']],
            'unmatched [ then dot'                   => ['a[b.c=1', ['a_b_c' => '1']],
            'unmatched inner ['                      => ['a[x][y=1', ['a' => ['x' => '1']]],
            'junk after ]'                           => ['a[x]junk=1', ['a' => ['x' => '1']]],
            'index kept raw'                         => ['a[ x.y]=1', ['a' => [' x.y' => '1']]],
            'base mangled, index raw'                => ['a.b[c.d]=1', ['a_b' => ['c.d' => '1']]],
            'invalid escapes kept'                   => ['a=%zz; b=%; c=%4', ['a' => '%zz', 'b' => '%', 'c' => '%4']],
            'tab'                                    => ["\ttab=1", ['tab' => '1']],
            'comma in name'                          => ['a,b=1', ['a,b' => '1']],
            'numeric names'                          => ['0=zero; 1[]=one', [0 => 'zero', 1 => ['one']]],
            'spacing'                                => [' b=2;c=3 ;  d=4', ['b' => '2', 'c' => '3 ', 'd' => '4']],
        ];
    }

    /** @param array<array-key, mixed> $expected */
    #[DataProvider('nativeCases')]
    public function testMatchesNativeCookieParsing(string $header, array $expected): void
    {
        $this->assertSame($expected, App::parseCookieHeader($header));
    }
}
