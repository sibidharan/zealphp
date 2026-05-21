<?php
namespace ZealPHP\Tests\Unit;

use ZealPHP\Tests\TestCase;
use ZealPHP\Input\RequestInput;
use ZealPHP\RequestContext;

class RequestInputTest extends TestCase
{
    /**
     * Reset the process-wide RequestContext singleton's request bags so each
     * bagFor() test starts from a clean, known state (no coroutine context in
     * unit runs → instance() hands back the singleton).
     */
    protected function tearDown(): void
    {
        $g = RequestContext::instance();
        $g->get = [];
        $g->post = [];
        $g->cookie = [];
        $g->server = [];
        parent::tearDown();
    }
    public function testFilterValueReturnsNullWhenKeyMissing(): void
    {
        $this->assertNull(RequestInput::filterValue([], 'absent', FILTER_DEFAULT, 0));
    }

    public function testFilterValueAppliesIntFilter(): void
    {
        $this->assertSame(42, RequestInput::filterValue(['n' => '42'], 'n', FILTER_VALIDATE_INT, 0));
    }

    public function testFilterValueFailsValidationReturnsFalse(): void
    {
        $this->assertFalse(RequestInput::filterValue(['n' => 'notanint'], 'n', FILTER_VALIDATE_INT, 0));
    }

    public function testFilterValueDefaultPassesThroughString(): void
    {
        $this->assertSame('hello', RequestInput::filterValue(['s' => 'hello'], 's', FILTER_DEFAULT, 0));
    }

    public function testFilterArrayAppliesPerKeyDefinition(): void
    {
        $bag = ['id' => '7', 'email' => 'a@b.com'];
        $def = ['id' => FILTER_VALIDATE_INT, 'email' => FILTER_VALIDATE_EMAIL];
        $out = RequestInput::filterArray($bag, $def, true);
        $this->assertSame(['id' => 7, 'email' => 'a@b.com'], $out);
    }

    public function testFilterArrayAddEmptyYieldsNullForMissingKeys(): void
    {
        $out = RequestInput::filterArray([], ['x' => FILTER_DEFAULT], true);
        $this->assertSame(['x' => null], $out);
    }

    public function testBagForUnknownTypeReturnsEmptyArray(): void
    {
        // Unknown INPUT_* type → empty bag (matches CLI "type unavailable").
        $this->assertSame([], RequestInput::bagFor(999));
    }

    /**
     * Each INPUT_* type must resolve to its OWN bag — removing any match arm
     * would route that type to `default => []` (empty), so distinct non-empty
     * bags pin every arm. Two keys per bag also kills the stringKeyed()
     * ArrayOneItem mutant (would slice each bag down to a single entry).
     */
    public function testBagForResolvesGetBag(): void
    {
        $g = RequestContext::instance();
        $g->get = ['gk1' => 'GV1', 'gk2' => 'GV2'];
        $this->assertSame(['gk1' => 'GV1', 'gk2' => 'GV2'], RequestInput::bagFor(INPUT_GET));
    }

    public function testBagForResolvesPostBag(): void
    {
        $g = RequestContext::instance();
        $g->post = ['pk1' => 'PV1', 'pk2' => 'PV2'];
        $this->assertSame(['pk1' => 'PV1', 'pk2' => 'PV2'], RequestInput::bagFor(INPUT_POST));
    }

    public function testBagForResolvesCookieBag(): void
    {
        $g = RequestContext::instance();
        $g->cookie = ['ck1' => 'CV1', 'ck2' => 'CV2'];
        $this->assertSame(['ck1' => 'CV1', 'ck2' => 'CV2'], RequestInput::bagFor(INPUT_COOKIE));
    }

    public function testBagForResolvesServerBag(): void
    {
        $g = RequestContext::instance();
        $g->server = ['SK1' => 'SV1', 'SK2' => 'SV2'];
        $this->assertSame(['SK1' => 'SV1', 'SK2' => 'SV2'], RequestInput::bagFor(INPUT_SERVER));
    }

    /**
     * INPUT_ENV resolves to $_ENV (read directly, not via $g). Pins the
     * INPUT_ENV match arm — removing it would route to default => [].
     */
    public function testBagForResolvesEnvBag(): void
    {
        $key = 'ZEALPHP_REQINPUT_ENV_PROBE';
        $had = array_key_exists($key, $_ENV);
        $prev = $_ENV[$key] ?? null;
        $_ENV[$key] = 'ENVVAL';
        try {
            $bag = RequestInput::bagFor(INPUT_ENV);
            $this->assertArrayHasKey($key, $bag);
            $this->assertSame('ENVVAL', $bag[$key]);
        } finally {
            if ($had) {
                $_ENV[$key] = $prev;
            } else {
                unset($_ENV[$key]);
            }
        }
    }

    /**
     * Each bag is independent: setting only GET leaves POST/COOKIE/SERVER empty.
     * Reinforces that an arm removal cannot be masked by another arm's data.
     */
    public function testBagForBagsAreIndependent(): void
    {
        $g = RequestContext::instance();
        $g->get = ['only' => 'get'];
        $this->assertSame(['only' => 'get'], RequestInput::bagFor(INPUT_GET));
        $this->assertSame([], RequestInput::bagFor(INPUT_POST));
        $this->assertSame([], RequestInput::bagFor(INPUT_COOKIE));
        $this->assertSame([], RequestInput::bagFor(INPUT_SERVER));
    }

    /**
     * stringKeyed() must coerce integer keys to strings and preserve EVERY
     * entry (kills the Foreach_ empty-loop mutant and the ArrayOneItem
     * single-slice mutant — bag has 3 entries, all must survive).
     */
    public function testBagForKeepsAllEntries(): void
    {
        // PHP normalizes numeric string keys back to ints in arrays, so the
        // (string) cast in stringKeyed() isn't separately observable — but the
        // loop must still visit and copy EVERY entry. Three entries pin both
        // the Foreach_ empty-loop mutant and the ArrayOneItem single-slice
        // mutant (which would drop two of them).
        $g = RequestContext::instance();
        $g->get = ['a' => 'one', 'b' => 'two', 'c' => 'three'];
        $out = RequestInput::bagFor(INPUT_GET);
        $this->assertSame(['a' => 'one', 'b' => 'two', 'c' => 'three'], $out);
        $this->assertCount(3, $out);
    }

    public function testZealFilterInputDelegatesAndReturnsNullForMissing(): void
    {
        // No request/coroutine context here → bag empty → null, never fatal.
        $this->assertNull(\ZealPHP\filter_input(INPUT_GET, 'whatever'));
    }
}
