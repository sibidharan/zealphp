<?php
// Component: split code + live output demo panel
// Variables: $id, $title, $code, $url, $lang, $method, $full (bool)
$id     ??= 'demo-' . rand(1000,9999);
$title  ??= '';
$code   ??= '';
$url    ??= '';
$lang   ??= 'php';
$method ??= 'GET';
$full   ??= false;
$badge_class = 'badge-' . strtolower($method);
?>
<div class="inject-case" id="<?= htmlspecialchars($id) ?>-wrap">
  <?php if ($title): ?>
  <div class="inject-case-header">
    <span class="badge <?= $badge_class ?>"><?= $method ?></span>
    <code><?= htmlspecialchars($title) ?></code>
  </div>
  <?php endif; ?>
  <div class="inject-case-body<?= $full ? ' demo-panel full' : '' ?>">
    <div class="demo-code">
      <pre><code class="language-<?= htmlspecialchars($lang) ?>"><?= htmlspecialchars(trim($code)) ?></code></pre>
    </div>
    <div class="demo-output" id="<?= htmlspecialchars($id) ?>-out">
      <span class="label">LIVE OUTPUT</span>
      <span class="demo-loading">Click Run →</span>
      <?php if ($url): ?>
      <button class="demo-run-btn"
              data-demo-url="<?= htmlspecialchars($url) ?>"
              data-target="<?= htmlspecialchars($id) ?>-out"
              data-autorun>Run</button>
      <?php endif; ?>
    </div>
  </div>
</div>
