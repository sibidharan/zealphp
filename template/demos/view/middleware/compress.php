<?php
/**
 * /demo/view/middleware/compress
 *
 * @var string $accepts the request's Accept-Encoding
 * @var bool   $gzip    whether it includes gzip
 */
use function ZealPHP\e;
?>
<p class="demo-shell-desc">OpenSwoole's built-in <code class="demo-inline">http_compression</code> is enabled by default in <code class="demo-inline">App::run()</code>. The response below is ~2.7&nbsp;KB raw but is sent as <code class="demo-inline">Content-Encoding: gzip</code> when the client advertises it.</p>
<h2 class="demo-shell-h">Your Accept-Encoding</h2>
<div class="inject-case">
  <div class="inject-case-body inject-case-body--solo">
    <div class="demo-output">
      <code class="demo-inline"><?= e($accepts) ?></code> <?= $gzip ? '✓ gzip supported' : '✗ no gzip in Accept-Encoding' ?>
    </div>
  </div>
</div>
<h2 class="demo-shell-h">Response</h2>
<div class="inject-case">
  <div class="inject-case-body inject-case-body--solo">
    <div class="demo-output">
      <dl class="demo-kv"><dt>Status</dt><dd><span class="demo-status s2xx">200 OK</span></dd><dt>Content-Encoding</dt><dd><code class="demo-inline"><?= $gzip ? 'gzip' : 'identity' ?></code> (depends on your Accept-Encoding)</dd><dt>Raw body size</dt><dd>≈ 2.7 KB</dd></dl>
    </div>
  </div>
</div>
