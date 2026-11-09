<?php
/**
 * The "Response" section of a demo viewer: status, content type and the
 * payload (JSON pretty-printed).
 *
 * @var int    $status
 * @var string $type     Content-Type
 * @var string $payload  raw response body
 */
use ZealPHP\Site\DemoHelpers;
use function ZealPHP\e;

$status = (int) ($status ?? 200);
$type   = (string) ($type ?? 'application/json');
?>
<h2 class="demo-shell-h">Response</h2>
<div class="inject-case">
  <div class="inject-case-body inject-case-body--solo">
    <div class="demo-output">
      <dl class="demo-kv"><dt>Status</dt><dd><span class="demo-status s<?= substr((string) $status, 0, 1) ?>xx"><?= $status ?> <?= e(DemoHelpers::_demo_phrase($status)) ?></span></dd><dt>Content-Type</dt><dd><?= e($type) ?></dd></dl><pre class="demo-payload demo-payload-gap"><?= e(DemoHelpers::demo_payload($type, (string) ($payload ?? ''))) ?></pre>
    </div>
  </div>
</div>
