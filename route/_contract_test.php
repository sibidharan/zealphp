<?php
/**
 * Test routes for tests/Integration/FileExecutionContractTest.php — exercise
 * every shape of the universal return contract through App::include(),
 * App::render(), App::renderToString(), and App::renderStream(). Demo
 * deployments don't need these; the file's name is underscore-prefixed so
 * /demo/* navigation never accidentally surfaces it.
 */

use ZealPHP\App;
use ZealPHP\RequestContext;

$app = App::instance();

// --- App::include() contract matrix --------------------------------------

$app->route('/_contract/include/echo-only', fn() =>
    App::include('/_contract_test/echo_only.php')
);

$app->route('/_contract/include/status', fn() =>
    App::include('/_contract_test/return_status.php')
);

$app->route('/_contract/include/array', fn() =>
    App::include('/_contract_test/return_array.php')
);

$app->route('/_contract/include/string', fn() =>
    App::include('/_contract_test/return_string.php')
);

$app->route('/_contract/include/echo-then-return', fn() =>
    App::include('/_contract_test/echo_then_return.php')
);

$app->route('/_contract/include/generator', fn() =>
    App::include('/_contract_test/return_generator.php')
);

$app->route('/_contract/include/echo-then-generator', fn() =>
    App::include('/_contract_test/echo_then_generator.php')
);

$app->route('/_contract/include/closure-param', fn() =>
    App::include('/_contract_test/closure_param.php', ['name' => 'alice'])
);

// Apache document-root parity — leading slash optional. Both should serve
// the same file.
$app->route('/_contract/include/no-leading-slash', fn() =>
    App::include('_contract_test/return_string.php')
);

// Server-vars auto-populated by App::include() before the file runs.
$app->route('/_contract/include/server-self', fn() =>
    App::include('/_contract_test/server_self.php')
);

// Path traversal: must NOT escape document_root.
$app->route('/_contract/include/traversal', fn() =>
    App::include('/../etc/passwd')
);

// Path resolving to outside public/ via realpath (returns false in our test).
$app->route('/_contract/include/missing', fn() =>
    App::include('/_contract_test/nope_does_not_exist.php')
);

// Deprecated alias must keep working — accepts absolute path.
$app->route('/_contract/includefile/legacy-alias', fn() =>
    App::includeFile(App::$cwd . '/public/_contract_test/return_status.php')
);

// --- App::render() BC + new contract ------------------------------------

// BC: render() echoes for void+echo templates so existing public/*.php works.
$app->route('/_contract/render/echo-only-bc', function () {
    App::render('/_contract_test/echo_only');
});

// Forwarding the return value: render() now flows non-string returns through.
$app->route('/_contract/render/status-passthrough', fn() =>
    App::render('/_contract_test/return_status')
);

$app->route('/_contract/render/array-passthrough', fn() =>
    App::render('/_contract_test/return_array')
);

$app->route('/_contract/render/generator-passthrough', fn() =>
    App::render('/_contract_test/return_generator')
);

// --- App::renderToString() wrapper --------------------------------------

$app->route('/_contract/render-to-string/echo', fn() =>
    App::renderToString('/_contract_test/echo_only')
);

$app->route('/_contract/render-to-string/generator', fn() =>
    App::renderToString('/_contract_test/return_generator')
);

$app->route('/_contract/render-to-string/array', fn() =>
    App::renderToString('/_contract_test/return_array')
);

// --- App::renderStream() wrapper ----------------------------------------

$app->route('/_contract/render-stream/echo', fn() =>
    App::renderStream('/_contract_test/echo_only')
);

$app->route('/_contract/render-stream/generator', fn() =>
    App::renderStream('/_contract_test/return_generator')
);

$app->route('/_contract/render-stream/closure-param', fn() =>
    App::renderStream('/_contract_test/closure_param', ['greeting' => 'Hello', 'name' => 'team'])
);

// --- Status code coercion (universal contract: 100-599; out-of-range → 500) ----
// One dynamic route that returns whatever int the URL says — exercises the
// coerceStatusCode() boundary in ResponseMiddleware. Negative numbers travel
// as `_<digits>` to dodge URL-path semantics.
$app->patternRoute('/_contract/status/(?P<code>_?[0-9]+)', function (string $code) {
    $n = (int)(str_starts_with($code, '_') ? '-' . substr($code, 1) : $code);
    return $n;
});

// Coroutine state isolation — for tests/Integration/CoroutineStateIsolationTest.php.
// Each request reads ?cid=N, writes it to $g->get['cid'] (per-coroutine in
// coroutine mode), then App::include()s a fixture that echoes it back. Under
// 100-way concurrent load every response must match its own cid — proving
// $g state is isolated per coroutine and there's no cross-coroutine leak.
// We deliberately sleep inside the route so requests overlap on the worker.
$app->route('/_contract/co-state', function ($request) {
    $g = RequestContext::instance();
    $cid = (string)($g->get['cid'] ?? '');
    // Force an await point so multiple coroutines interleave on the worker.
    \OpenSwoole\Coroutine::sleep(0.005);
    // Re-read after the sleep to confirm $g->get isn't clobbered by other
    // coroutines that ran during the sleep.
    $g->get['cid'] = $cid;
    return App::include('/_contract_test/co_state.php');
});
