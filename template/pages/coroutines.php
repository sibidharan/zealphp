<?php use ZealPHP\App; ?>
<section class="section">
<div class="container">
<h1 class="section-title">Coroutines</h1>
<p class="section-desc">OpenSwoole coroutines are cooperative — they yield only on I/O, making parallel fetch trivial. ZealPHP enables HOOK_ALL so all PHP I/O (file, curl, PDO) becomes coroutine-aware automatically.</p>

<?php
$demos = [
  ['co-parallel', 'Parallel fetch — 3 coroutines in 1s not 3s', '/demo/coroutine/parallel',
   <<<'PHP'
$app->route('/demo/coroutine/parallel', function() {
    $ch    = new Channel(3);
    $start = microtime(true);

    go(fn() => [$ch->push(simulated_fetch('users',  1))]);
    go(fn() => [$ch->push(simulated_fetch('orders', 1))]);
    go(fn() => [$ch->push(simulated_fetch('stats',  1))]);

    $results = [];
    for ($i = 0; $i < 3; $i++) $results[] = $ch->pop();

    return ['results' => $results, 'elapsed_s' => round(microtime(true) - $start, 3)];
    // All 3 run in parallel → ~1s total, not 3s
});
PHP],
  ['co-channel', 'Channel — producer/consumer pattern', '/demo/coroutine/channel',
   <<<'PHP'
$app->route('/demo/coroutine/channel', function() {
    $ch = new Channel(1); // buffer of 1

    go(function() use ($ch) {
        co::sleep(1);
        $ch->push(['value' => 42, 'from' => 'producer coroutine']);
    });

    $result = $ch->pop(); // blocks until producer pushes
    return ['received' => $result, 'pattern' => 'producer/consumer'];
});
PHP],
];
foreach ($demos as [$id, $title, $url, $code]) {
    App::render('/components/_demo', compact('id', 'title', 'url', 'code'));
}
?>

<h2 style="margin:2rem 0 .5rem">How it works</h2>
<table class="ztable">
  <tr><th>Primitive</th><th>Purpose</th></tr>
  <tr><td><code>go(callable)</code></td><td>Spawn a coroutine. Runs concurrently when current coroutine yields.</td></tr>
  <tr><td><code>co::sleep(float $s)</code></td><td>Yield for N seconds without blocking the event loop.</td></tr>
  <tr><td><code>new Channel(int $capacity)</code></td><td>Buffered queue for coroutine communication. <code>push()</code> + <code>pop()</code>.</td></tr>
  <tr><td><code>usleep(int $us)</code></td><td>Coroutine-aware micro-sleep under HOOK_ALL (use for sub-second delays).</td></tr>
  <tr><td><code>OpenSwoole\Runtime::HOOK_ALL</code></td><td>Makes all PHP I/O — curl, file, PDO, sleep — yield the event loop.</td></tr>
</table>

<div class="callout info" style="margin-top:1.5rem">
  <strong>App::superglobals(false)</strong> must be called before App::init() to enable coroutine mode.
  In coroutine mode, every request runs in its own coroutine with isolated <code>G::instance()</code> state.
</div>
</div>
</section>
