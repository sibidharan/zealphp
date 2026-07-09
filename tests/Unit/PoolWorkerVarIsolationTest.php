<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * #467 — the legacy-cgi pool worker runs the request `include` at GLOBAL scope
 * (so a legacy app's top-level vars become real $GLOBALS), which means every
 * worker-state variable the loop reads back AFTER the include must be
 * collision-proof against app globals. The request-loop control vars were
 * originally bare `$count`/`$maxRequests`: WordPress leaves a non-alphanumeric
 * global `$count`, so the worker's `$count++` emitted "Increment on
 * non-alphanumeric string" every request and the app could corrupt the
 * subprocess recycle counter. The fix renamed them `$__pw_count`/
 * `$__pw_max_requests`.
 *
 * This is a token-level contract test: no `$count`/`$maxRequests` may exist at
 * the file's TOP-LEVEL scope (function/closure bodies are fine — they have
 * their own scope), and the `$__pw_`-prefixed replacements must be present.
 */
final class PoolWorkerVarIsolationTest extends TestCase
{
    /** @return array<string, true> variable names seen at brace-depth 0 */
    private function topLevelVariables(string $source): array
    {
        $depth = 0;
        $seen = [];
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                [$id, $text] = $token;
                if ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                    $depth++;
                } elseif ($id === T_VARIABLE && $depth === 0) {
                    $seen[$text] = true;
                }
                continue;
            }
            if ($token === '{') {
                $depth++;
            } elseif ($token === '}') {
                $depth--;
            }
        }
        return $seen;
    }

    public function testRequestLoopControlVarsArePrefixed(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../src/pool_worker.php');
        $this->assertNotSame('', $source, 'pool_worker.php must be readable');

        $vars = $this->topLevelVariables($source);

        $this->assertArrayNotHasKey('$count', $vars,
            '#467 regression: bare $count at pool-worker global scope collides with app globals (WordPress)');
        $this->assertArrayNotHasKey('$maxRequests', $vars,
            '#467 regression: bare $maxRequests at pool-worker global scope is app-corruptible recycle state');

        $this->assertArrayHasKey('$__pw_count', $vars,
            'the __pw_-prefixed request counter must exist at top level');
        $this->assertArrayHasKey('$__pw_max_requests', $vars,
            'the __pw_-prefixed recycle ceiling must exist at top level');
    }
}
