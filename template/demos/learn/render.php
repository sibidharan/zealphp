<?php
/**
 * /api/learn/demo/render body: the route returns App::render() of the demo
 * shell, so the rendered template is the response.
 *
 * @var \ZealPHP\SafeHtml $clock the clock card
 */
?>
<p class="demo-shell-desc">The route returns <code>App::render()</code>, so the rendered template is the response.</p>
<h2 class="demo-shell-h">One-shot render</h2>
<div class="inject-case">
  <div class="inject-case-body inject-case-body--solo">
    <div class="demo-output">
      <?= $clock ?>
    </div>
  </div>
</div>
