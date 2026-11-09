<?php
/**
 * /api/learn/demo/render-stream — a streaming template: rendered with
 * App::render(..., stream: true), it sends the page head at once, then each
 * row the route yields as it is produced.
 *
 * @var iterable<string> $rows
 */
use ZealPHP\App;

return function (iterable $rows) {
    yield (string) App::component('/demos/components/learn_head', [
        'title' => 'App::render(stream: true) demo',
        'note'  => 'streaming 12 rows over ~1.8s',
    ]);
    yield '  <section class="render-demo"><h4>Streamed rows</h4>';
    yield from $rows;
    yield '</section></body></html>';
};
