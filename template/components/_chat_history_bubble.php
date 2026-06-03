<?php
$role  = ($role ?? 'assistant') === 'user' ? 'user' : 'assistant';
$items = $items ?? [];
?>
<div class="chat-msg <?= $role ?>">
  <div class="chat-bubble">
    <?php foreach ($items as $item): ?>
      <?php if (($item['type'] ?? '') === 'text'): ?>
        <?php // Stored chat HTML is server-generated (user turns are escaped at
              // write time). Defense-in-depth: pass it through a formatting-only
              // tag allowlist so any untrusted markup that ever reaches this
              // field cannot inject <script>/<iframe>/<img onerror>/<a href=js>. ?>
        <div class="chat-item text"><?= strip_tags((string)($item['html'] ?? ''), '<p><br><strong><em><b><i><u><code><pre><ul><ol><li><blockquote><h1><h2><h3><h4><span>') ?></div>
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
