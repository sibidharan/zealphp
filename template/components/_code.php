<?php
// Component: syntax-highlighted code block
// Variables: $code (string), $lang (string, default 'php'), $label (string, optional)
$lang  ??= 'php';
$label ??= '';
$code  ??= '';
?>
<?php if ($label): ?><div class="code-label"><?= htmlspecialchars($label) ?></div><?php endif; ?>
<div class="code-block">
  <pre><code class="language-<?= htmlspecialchars($lang) ?>"><?= htmlspecialchars(trim($code)) ?></code></pre>
</div>
