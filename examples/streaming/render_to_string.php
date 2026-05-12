<?php
/**
 * App::renderToString() + Streaming
 *
 * App::render() echoes into the active output buffer — it cannot be used
 * directly inside stream() or a Generator because there is no buffer there.
 *
 * App::renderToString() wraps render() in its own ob_start()/ob_get_clean()
 * and returns the HTML as a string, making it safe to $write() or yield.
 *
 * This example uses inline "template" functions to stay self-contained
 * (no files in template/ required).  Replace the inline closures with
 * App::renderToString('header', ['title' => '...']) in a real project.
 *
 * curl -N --no-buffer http://localhost:8080/examples/render-to-string
 */

use OpenSwoole\Coroutine as co;
use OpenSwoole\Coroutine\Channel;
use ZealPHP\App;

// Inline template helpers (stand-ins for real template files)
$tpl = [
    'header' => fn(string $title) => <<<HTML
        <!doctype html><html lang="en"><head>
          <meta charset="utf-8"><title>$title</title>
          <style>
            body{font-family:system-ui,sans-serif;max-width:720px;margin:2rem auto;padding:0 1rem}
            .card{border:1px solid #ddd;border-radius:8px;padding:1rem;margin:1rem 0}
            .badge{display:inline-block;background:#f0ffe0;color:#040;border-radius:4px;padding:2px 8px;font-size:.8rem}
            .skeleton{height:80px;background:linear-gradient(90deg,#eee 25%,#f5f5f5 50%,#eee 75%);
                       background-size:200%;animation:shimmer 1.2s infinite;border-radius:6px;margin:.5rem 0}
            @keyframes shimmer{0%{background-position:200%}100%{background-position:-200%}}
          </style>
        </head><body>
          <h1>$title <span class="badge">renderToString()</span></h1>
    HTML,

    'skeleton' => fn(string $id) => "<div class=\"skeleton\" id=\"$id\"></div>\n",

    'section' => fn(string $id, string $heading, string $body) => <<<HTML
        <script>document.getElementById('$id')?.remove();</script>
        <div class="card" id="{$id}_done"><h2>$heading</h2>$body</div>
    HTML,

    'footer' => fn(float $elapsed) => sprintf(
        '<p><em>Page complete in %.2fs.</em></p></body></html>', $elapsed
    ),
];

$start = microtime(true);

// Use Generator streaming so the route handler is clean
return (function() use ($tpl, $start) {

    // 1. Header + skeleton placeholders — browser renders immediately
    yield $tpl['header']('ZealPHP renderToString() Demo');
    yield '<p>Skeleton placeholders appear instantly; sections swap in as data arrives.</p>';
    yield $tpl['skeleton']('sk_users');
    yield $tpl['skeleton']('sk_posts');

    // 2. Parallel coroutine fetches
    $ch = new Channel(2);

    go(function() use ($ch, $tpl) {
        co::sleep(1);
        $html = '<ul><li>Alice — admin</li><li>Bob — editor</li><li>Charlie — viewer</li></ul>';
        $ch->push(['id' => 'sk_users', 'heading' => 'Users (1s fetch)', 'html' => $html]);
    });

    go(function() use ($ch, $tpl) {
        co::sleep(2.5);
        $html = '<ul><li>ZealPHP SSR Streaming — published</li><li>OpenSwoole Coroutines — draft</li></ul>';
        $ch->push(['id' => 'sk_posts', 'heading' => 'Posts (2.5s fetch)', 'html' => $html]);
    });

    // 3. Stream each section as its coroutine resolves
    for ($i = 0; $i < 2; $i++) {
        $r = $ch->pop();
        // renderToString() is safe here — captures into its own ob buffer
        // In a real project: App::renderToString('section', $r)
        yield $tpl['section']($r['id'], $r['heading'], $r['html']);
    }

    // 4. Footer
    yield $tpl['footer'](microtime(true) - $start);
})();
