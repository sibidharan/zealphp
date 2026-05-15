<?php
$id    = (int)($id ?? 0);
$title = (string)($title ?? '');
$body  = (string)($body ?? '');
$ts    = (int)($updated_at ?? time());
?>
<article class="note" id="note-<?= $id ?>" data-id="<?= $id ?>">
  <details>
    <summary class="note-title"><?= htmlspecialchars($title) ?></summary>
    <p class="note-body"><?= nl2br(htmlspecialchars($body)) ?></p>
  </details>
  <div class="note-meta">
    <span>Updated <?= date('Y-m-d H:i', $ts) ?></span>
    <button hx-delete="/api/learn/notes/<?= $id ?>" hx-target="#note-<?= $id ?>" hx-swap="outerHTML" hx-confirm="Delete this note?">Delete</button>
  </div>
</article>
