<?php
$role  = ($role ?? 'assistant') === 'user' ? 'user' : 'assistant';
$items = $items ?? [];
?>
<div class="chat-msg <?= $role ?>">
  <div class="chat-bubble">
    <?php foreach ($items as $item): ?>
      <?php if (($item['type'] ?? '') === 'text'): ?>
        <div class="chat-item text"><?= $item['html'] ?? '' ?></div>
      <?php elseif (($item['type'] ?? '') === 'tool'): ?>
        <div class="chat-item tool" data-id="<?= htmlspecialchars($item['id'] ?? '') ?>" data-status="<?= htmlspecialchars($item['status'] ?? 'ok') ?>">
          <div class="tool-head">
            <span class="tool-icon">⚙</span>
            <span class="tool-name"><?= htmlspecialchars($item['name'] ?? '') ?></span>
            <span class="tool-status"><?= ($item['status'] ?? '') === 'error' ? 'failed' : 'done' ?></span>
          </div>
          <details class="tool-detail">
            <summary>args + result</summary>
            <pre class="tool-args"><?= htmlspecialchars($item['args'] ?? '') ?></pre>
            <?php if (!empty($item['result'])): ?>
              <pre class="tool-result"><?= htmlspecialchars($item['result']) ?></pre>
            <?php endif; ?>
          </details>
        </div>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>
</div>
