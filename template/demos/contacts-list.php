<?php
use ZealPHP\App;

/**
 * App::fragment() demo — the htmx-essay template-fragment pattern.
 *
 * Two distinct render modes from the same file:
 *   - $fragment === null  → full page: render the list of collapsed rows
 *                            inline. No App::fragment() blocks execute.
 *   - $fragment is set    → only the fragment-definition section runs; the
 *                            matched App::fragment() block extracts via
 *                            HaltException, the rest of the file short-circuits.
 *
 * Gating the fragment defs behind `if ($fragment !== null)` is intentional.
 * In simpler cases (a list where every row is the same in both modes) you
 * can wrap each row in App::fragment() inline — both renders use the same
 * markup. Here we want a DIFFERENT layout for the full page (compact rows)
 * vs the swap (expanded card with email), so the two states live in their
 * own branches.
 *
 * @var array<int, array{id:int, name:string, role:string, email:string}> $contacts
 * @var string|null $fragment
 */
?>
<div class="contacts-fragment-demo">
  <?php if ($fragment === null): ?>
    <!-- Full page: each row renders inline. NO App::fragment() in this branch
         — they're only defined below in the else-arm and never execute here.
         A click on a row's button fires a fresh request with ?fragment=… which
         re-enters this template in the other branch. -->
    <ul class="contact-list" id="contacts">
      <?php foreach ($contacts as $c): ?>
        <li class="contact-row" id="row-<?= (int)$c['id'] ?>">
          <div class="contact-info">
            <span class="contact-name"><?= htmlspecialchars((string)$c['name']) ?></span>
            <span class="contact-role"><?= htmlspecialchars((string)$c['role']) ?></span>
          </div>
          <button class="toggle-btn"
                  hx-get="/demo/fragments/contacts?fragment=contact-<?= (int)$c['id'] ?>"
                  hx-target="#row-<?= (int)$c['id'] ?>"
                  hx-swap="outerHTML">
            Show details<span class="htmx-indicator"> …</span>
          </button>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php else: ?>
    <!-- Fragment-extraction mode: define each named region. App::fragment()
         either runs its closure inline (no — not in this branch since the
         caller asked for a specific fragment) OR captures and short-circuits.
         The first matching block clears the buffer and throws HaltException;
         the rest never executes. -->
    <?php foreach ($contacts as $c): ?>
      <?php App::fragment("contact-{$c['id']}", function() use ($c) { ?>
        <li class="contact-row expanded" id="row-<?= (int)$c['id'] ?>">
          <div class="contact-info">
            <span class="contact-name"><?= htmlspecialchars((string)$c['name']) ?></span>
            <span class="contact-role"><?= htmlspecialchars((string)$c['role']) ?></span>
            <span class="contact-email"><?= htmlspecialchars((string)$c['email']) ?></span>
          </div>
          <button class="toggle-btn"
                  hx-get="/demo/fragments/contacts?fragment=contact-<?= (int)$c['id'] ?>-collapsed"
                  hx-target="#row-<?= (int)$c['id'] ?>"
                  hx-swap="outerHTML">
            Hide details<span class="htmx-indicator"> …</span>
          </button>
        </li>
      <?php }); ?>
      <?php App::fragment("contact-{$c['id']}-collapsed", function() use ($c) { ?>
        <li class="contact-row" id="row-<?= (int)$c['id'] ?>">
          <div class="contact-info">
            <span class="contact-name"><?= htmlspecialchars((string)$c['name']) ?></span>
            <span class="contact-role"><?= htmlspecialchars((string)$c['role']) ?></span>
          </div>
          <button class="toggle-btn"
                  hx-get="/demo/fragments/contacts?fragment=contact-<?= (int)$c['id'] ?>"
                  hx-target="#row-<?= (int)$c['id'] ?>"
                  hx-swap="outerHTML">
            Show details<span class="htmx-indicator"> …</span>
          </button>
        </li>
      <?php }); ?>
    <?php endforeach; ?>
  <?php endif; ?>
</div>
