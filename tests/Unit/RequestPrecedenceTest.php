<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use ZealPHP\App;
use ZealPHP\Tests\TestCase;

/**
 * #356 — `$_REQUEST` GET/POST precedence. PHP's default `request_order='GP'`
 * merges GET first then POST, with later sources overwriting earlier ones, so a
 * key present in BOTH GET and POST takes the POST value (the form-submission-
 * overrides-querystring convention). ZealPHP's `mixed`/`coroutine-legacy`
 * superglobal populate built `$_REQUEST` as `$get + $post`, but PHP's `+`
 * array-union keeps the LEFT operand on a collision → GET-wins, the inverse of
 * the reference. The fix flips it to `$post + $get` (POST-wins), factored into
 * App::composeRequestArray() — the single source of truth used by BOTH the
 * OnRequest populate and the CGI-context request builder.
 */
final class RequestPrecedenceTest extends TestCase
{
    public function testPostWinsOnCollidingKey(): void
    {
        $req = App::composeRequestArray(
            ['shared' => 'GET', 'only_get' => 'g'],
            ['shared' => 'POST', 'only_post' => 'p']
        );
        // The headline contract: POST overrides GET (request_order='GP').
        $this->assertSame('POST', $req['shared'], '$_REQUEST POST must override GET on a shared key');
        // Non-colliding keys from BOTH sources survive.
        $this->assertSame('g', $req['only_get']);
        $this->assertSame('p', $req['only_post']);
    }

    public function testGetValueUsedWhenPostLacksKey(): void
    {
        $req = App::composeRequestArray(['q' => 'search'], []);
        $this->assertSame('search', $req['q']);
    }

    public function testPostValueUsedWhenGetLacksKey(): void
    {
        $req = App::composeRequestArray([], ['token' => 'csrf123']);
        $this->assertSame('csrf123', $req['token']);
    }

    public function testEmptyBothYieldsEmpty(): void
    {
        $this->assertSame([], App::composeRequestArray([], []));
    }

    public function testCookieIsNotMerged(): void
    {
        // request_order='GP' deliberately excludes COOKIE — composeRequestArray
        // takes only GET + POST, so a cookie-named key never bleeds in. (Key
        // ORDER is unspecified — `$post + $get` lists POST keys first — so assert
        // on membership + values, not order.)
        $req = App::composeRequestArray(['a' => 'g'], ['b' => 'p']);
        $this->assertCount(2, $req);
        $this->assertSame('g', $req['a']);
        $this->assertSame('p', $req['b']);
    }

    public function testEveryCollidingKeyTakesPost(): void
    {
        // Multiple collisions — proves it's not just the first key.
        $req = App::composeRequestArray(
            ['x' => 'GX', 'y' => 'GY', 'z' => 'GZ'],
            ['x' => 'PX', 'y' => 'PY']
        );
        $this->assertSame('PX', $req['x']);
        $this->assertSame('PY', $req['y']);
        $this->assertSame('GZ', $req['z'], 'a GET-only key survives');
    }
}
