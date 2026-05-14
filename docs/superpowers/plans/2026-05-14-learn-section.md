# `/learn` Section Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship a 12-lesson `/learn` tutorial section on the existing ZealPHP OSS site, anchored by a working Notes + AI-chat app backed by SQLite + session auth.

**Architecture:** Extends the existing site (top-nav entry + sidebar inside content area). SQLite via PDO for users/notes, accessed by both PHP and a Python OpenAI Agents SDK process spawned per chat turn. SSE proxy translates Agents SDK stream events (text deltas + tool calls) into a frontend timeline UI. Mock mode runs against the same SQLite when no `OPENAI_API_KEY` is set.

**Tech Stack:** PHP 8.3, OpenSwoole, ZealPHP, PDO+SQLite (WAL), htmx 1.9 CDN, Python 3.10+ via `uv`, OpenAI Agents SDK. Server runs on port **8090** during development to avoid conflicting with the main demo on 8080.

**Spec:** `docs/superpowers/specs/2026-05-14-learn-section-design.md`

---

## File Structure

### New files

| Path | Purpose |
|---|---|
| `public/learn.php` | `/learn` index — Quick Start |
| `public/learn/create-app.php` | Lesson 2 |
| `public/learn/first-page.php` | Lesson 3 |
| `public/learn/components.php` | Lesson 4 |
| `public/learn/routing.php` | Lesson 5 |
| `public/learn/sessions.php` | Lesson 6 |
| `public/learn/htmx.php` | Lesson 7 |
| `public/learn/notes.php` | Lesson 8 |
| `public/learn/ai-chat.php` | Lesson 9 |
| `public/learn/async.php` | Lesson 10 |
| `public/learn/deployment.php` | Lesson 11 |
| `public/learn/philosophy.php` | Lesson 12 |
| `public/css/learn.css` | Sidebar + lesson + chat + notes styles |
| `public/js/learn.js` | htmx loader + chat timeline client |
| `route/learn.php` | All `/api/learn/*` endpoints + DB bootstrap helper |
| `template/_learn_sidebar.php` | Sticky sidebar (rendered by each lesson page) |
| `template/pages/learn.php` | Quick Start body |
| `template/pages/learn/create-app.php` | …lesson body templates (one per lesson) |
| `template/pages/learn/first-page.php` | |
| `template/pages/learn/components.php` | |
| `template/pages/learn/routing.php` | |
| `template/pages/learn/sessions.php` | |
| `template/pages/learn/htmx.php` | |
| `template/pages/learn/notes.php` | |
| `template/pages/learn/ai-chat.php` | |
| `template/pages/learn/async.php` | |
| `template/pages/learn/deployment.php` | |
| `template/pages/learn/philosophy.php` | |
| `template/components/_callout.php` | info/warn box |
| `template/components/_lesson_header.php` | Title + breadcrumb + prev/next chips |
| `template/components/_youwilllearn.php` | "You will learn" bullet list |
| `template/components/_deepdive.php` | Collapsible `<details>` |
| `template/components/_tryit.php` | Bordered "Try it now" panel |
| `template/components/_note_card.php` | Single note `<article>` |
| `template/components/_counter_button.php` | Lesson 7 htmx demo |
| `examples/agents/notes_agent.py` | Python OpenAI Agents SDK chat agent |
| `tests/Unit/LearnAuthTest.php` | Auth helper unit tests |
| `tests/Unit/LearnNotesRepoTest.php` | Notes repo unit tests |
| `tests/Integration/LearnApiTest.php` | End-to-end API tests |
| `scripts/extract-learn-repo.sh` | Companion-repo extraction |
| `docs/learn-app.md` | Internal dev notes / companion README source |
| `.env.example` | Documented env vars |

### Modified files

| Path | Change |
|---|---|
| `template/_nav.php` | Add `'learn' => ['/learn', 'Learn']` entry + prefix-match for active state |
| `template/_master.php` | Add `'page'` to the `compact()` call passed to `_head` |
| `template/_head.php` | Conditional `<link>`/`<script>` for `learn.css`/`learn.js` when `$page` starts with `learn` |
| `.gitignore` | Add `/storage/learn.db*` |

---

## Milestone 0 — Foundation (port, env, gitignore, dirs)

### Task 0.1: Create storage dir + env example + gitignore

**Files:**
- Create: `.env.example`
- Create: `storage/.gitkeep`
- Modify: `.gitignore`

- [ ] **Step 1: Create `.env.example`**

```bash
mkdir -p storage
```

Write `/var/labsstorage/home/sibidharan/zealphp/.env.example`:

```
# ZealPHP Learn — environment variables
# Copy to .env and customize. The .env file is gitignored.

# OpenAI API key for /learn/ai-chat. Without it, mock mode runs.
OPENAI_API_KEY=

# Model name for the Python notes agent.
ZEALPHP_LEARN_AI_MODEL=gpt-4.1-mini

# Chat turn rate limit per IP per hour.
ZEALPHP_LEARN_RATE_LIMIT=30

# Per-user note count cap (cheap guard for the tutorial).
ZEALPHP_LEARN_MAX_NOTES=256

# SQLite path; relative paths are resolved against repo root.
ZEALPHP_LEARN_DB_PATH=storage/learn.db

# Dev port for the learn app verification.
ZEALPHP_LEARN_PORT=8090
```

- [ ] **Step 2: Write `storage/.gitkeep` and append `.gitignore`**

Write `/var/labsstorage/home/sibidharan/zealphp/storage/.gitkeep` (empty file).

Append to `/var/labsstorage/home/sibidharan/zealphp/.gitignore`:

```
/storage/learn.db
/storage/learn.db-wal
/storage/learn.db-shm
/storage/learn.test.db
/storage/learn.test.db-wal
/storage/learn.test.db-shm
```

- [ ] **Step 3: Verify**

```bash
ls -la storage/.gitkeep .env.example && grep learn.db .gitignore
```

Expected: file exists, gitignore contains the four db patterns.

- [ ] **Step 4: Commit**

```bash
git add storage/.gitkeep .env.example .gitignore
git commit -m "feat(learn): env scaffolding + storage dir + db gitignores"
```

### Task 0.2: Run a baseline smoke test of the existing app

Sanity-check that the existing app still boots before we change anything. We'll use port 8090 going forward.

- [ ] **Step 1: Start existing app on port 8090 in background**

```bash
ZEALPHP_LEARN_PORT=8090 php app.php start -p 8090 -d --pid-file /tmp/zealphp/learn_dev.pid
sleep 2
curl -fsS http://127.0.0.1:8090/ -o /dev/null -w "%{http_code}\n"
```

Expected: `200`.

- [ ] **Step 2: Stop the server**

```bash
php app.php stop -p 8090 --pid-file /tmp/zealphp/learn_dev.pid
```

Expected: server stops cleanly. No commit (no code change).

---

## Milestone 1 — Lesson scaffold + nav

Goal: clicking "Learn" in the top nav lands on `/learn`; navigating to each `/learn/<slug>` returns 200 with a placeholder page; sidebar highlights the active lesson.

### Task 1.1: Add `learn` to the top nav with prefix-match active state

**Files:**
- Modify: `template/_nav.php` (insert after `'getting-started'`)

- [ ] **Step 1: Edit `template/_nav.php`**

Insert this entry into the `$links` array, immediately after the `'getting-started'` line:

```php
  'learn'           => ['/learn',          'Learn'],
```

Then replace the active-class comparison in both `<a>` lines. Find each occurrence of:

```php
<a href="<?= $href ?>"<?= ($active === $key ? ' class="active"' : '') ?>><?= $label ?></a>
```

Replace with:

```php
<?php
  $isActive = ($key === 'learn')
    ? ($active === 'learn' || str_starts_with((string)$active, 'learn/'))
    : ($active === $key);
?>
<a href="<?= $href ?>"<?= $isActive ? ' class="active"' : '' ?>><?= $label ?></a>
```

- [ ] **Step 2: Forward `$page` to `_head.php` from `_master.php`**

Modify `template/_master.php` — replace:

```php
<?php App::render('/_head', compact('title', 'description')); ?>
```

with:

```php
<?php App::render('/_head', compact('title', 'description', 'page')); ?>
```

- [ ] **Step 3: Inject learn-specific assets in `_head.php`**

Append to `template/_head.php`, before the closing `</head>`:

```php
<?php if (str_starts_with((string)($page ?? ''), 'learn')): ?>
  <link rel="stylesheet" href="/css/learn.css">
  <script src="https://unpkg.com/htmx.org@1.9.12" defer></script>
  <script src="/js/learn.js" defer></script>
<?php endif; ?>
```

- [ ] **Step 4: Commit**

```bash
git add template/_nav.php template/_master.php template/_head.php
git commit -m "feat(learn): top-nav entry + conditional learn assets in _head"
```

### Task 1.2: Create the sidebar template

**Files:**
- Create: `template/_learn_sidebar.php`

- [ ] **Step 1: Write the sidebar template**

Write `/var/labsstorage/home/sibidharan/zealphp/template/_learn_sidebar.php`:

```php
<?php
$active ??= 'learn';
$groups = [
  'Get Started' => [
    ['learn',              'Quick Start'],
    ['learn/create-app',   'Create a ZealPHP App'],
    ['learn/first-page',   'Your First Page'],
  ],
  'Core Concepts' => [
    ['learn/components',   'Components'],
    ['learn/routing',      'Routing'],
    ['learn/sessions',     'Sessions & Auth'],
    ['learn/htmx',         'Add htmx'],
  ],
  'Build the App' => [
    ['learn/notes',        'Build Personal Notes'],
    ['learn/ai-chat',      'Add AI Chat'],
    ['learn/async',        'Async & Coroutines'],
    ['learn/deployment',   'Deployment'],
    ['learn/philosophy',   'Philosophy'],
  ],
];
?>
<input type="checkbox" id="learn-sidebar-toggle" class="learn-sidebar-toggle-input">
<label for="learn-sidebar-toggle" class="learn-sidebar-toggle-btn" aria-label="Toggle lessons">☰ Lessons</label>
<aside class="learn-sidebar" aria-label="Lesson navigation">
  <div class="learn-sidebar-inner">
    <?php $i = 1; foreach ($groups as $title => $items): ?>
      <div class="learn-sidebar-group">
        <h4 class="learn-sidebar-group-title"><?= htmlspecialchars($title) ?></h4>
        <ol class="learn-sidebar-list" start="<?= $i ?>">
          <?php foreach ($items as [$slug, $label]): ?>
            <li<?= $active === $slug ? ' class="active"' : '' ?>>
              <a href="/<?= $slug ?>"><?= htmlspecialchars($label) ?></a>
            </li>
            <?php $i++; endforeach; ?>
        </ol>
      </div>
    <?php endforeach; ?>
  </div>
</aside>
```

- [ ] **Step 2: Commit**

```bash
git add template/_learn_sidebar.php
git commit -m "feat(learn): sidebar template with 12 lessons in 3 groups"
```

### Task 1.3: Create the 5 lesson UI components

**Files:**
- Create: `template/components/_callout.php`
- Create: `template/components/_lesson_header.php`
- Create: `template/components/_youwilllearn.php`
- Create: `template/components/_deepdive.php`
- Create: `template/components/_tryit.php`

- [ ] **Step 1: Write `_callout.php`**

```php
<?php
$variant ??= 'info';   // info | warn | success | deep
$title   ??= '';
$icon    = [ 'info' => 'ℹ', 'warn' => '⚠', 'success' => '✓', 'deep' => '🔎' ][$variant] ?? 'ℹ';
?>
<aside class="callout callout-<?= htmlspecialchars($variant) ?>">
  <div class="callout-head"><span class="callout-icon"><?= $icon ?></span><?php if ($title !== ''): ?><strong><?= htmlspecialchars($title) ?></strong><?php endif; ?></div>
  <div class="callout-body"><?= $body ?? '' ?></div>
</aside>
```

- [ ] **Step 2: Write `_lesson_header.php`**

```php
<?php
$number   ??= 0;
$title    ??= 'Lesson';
$subtitle ??= '';
$prev     ??= null;   // ['slug' => 'learn/x', 'title' => 'Prev']
$next     ??= null;
?>
<header class="lesson-header">
  <nav class="lesson-crumb"><a href="/learn">ZealPHP Learn</a> &nbsp;›&nbsp; Lesson <?= (int)$number ?></nav>
  <h1 class="lesson-title"><?= htmlspecialchars($title) ?></h1>
  <?php if ($subtitle !== ''): ?><p class="lesson-subtitle"><?= htmlspecialchars($subtitle) ?></p><?php endif; ?>
  <div class="lesson-chips">
    <?php if ($prev): ?><a class="lesson-chip lesson-chip-prev" href="/<?= htmlspecialchars($prev['slug']) ?>">← <?= htmlspecialchars($prev['title']) ?></a><?php endif; ?>
    <?php if ($next): ?><a class="lesson-chip lesson-chip-next" href="/<?= htmlspecialchars($next['slug']) ?>"><?= htmlspecialchars($next['title']) ?> →</a><?php endif; ?>
  </div>
</header>
```

- [ ] **Step 3: Write `_youwilllearn.php`**

```php
<?php $items ??= []; ?>
<section class="youwilllearn">
  <h3>You will learn</h3>
  <ul>
    <?php foreach ($items as $item): ?>
      <li><?= htmlspecialchars($item) ?></li>
    <?php endforeach; ?>
  </ul>
</section>
```

- [ ] **Step 4: Write `_deepdive.php`**

```php
<?php $title ??= 'Deep dive'; ?>
<details class="deepdive">
  <summary><span class="deepdive-icon">🔎</span> <?= htmlspecialchars($title) ?></summary>
  <div class="deepdive-body"><?= $body ?? '' ?></div>
</details>
```

- [ ] **Step 5: Write `_tryit.php`**

```php
<?php $title ??= 'Try it now'; ?>
<section class="tryit">
  <header class="tryit-head"><span class="tryit-icon">▶</span> <?= htmlspecialchars($title) ?></header>
  <div class="tryit-body"><?= $body ?? '' ?></div>
</section>
```

- [ ] **Step 6: Commit**

```bash
git add template/components/_callout.php template/components/_lesson_header.php template/components/_youwilllearn.php template/components/_deepdive.php template/components/_tryit.php
git commit -m "feat(learn): 5 lesson UI components (callout, lesson_header, youwilllearn, deepdive, tryit)"
```

### Task 1.4: Add baseline learn CSS

**Files:**
- Create: `public/css/learn.css`

- [ ] **Step 1: Write `learn.css`**

Write `/var/labsstorage/home/sibidharan/zealphp/public/css/learn.css`:

```css
/* /learn — layout, sidebar, lesson components, chat, notes */

.learn-layout {
  display: grid;
  grid-template-columns: 260px 1fr;
  gap: 2.5rem;
  max-width: 1200px;
  margin: 0 auto;
  padding: 1.5rem;
}

@media (max-width: 900px) {
  .learn-layout { grid-template-columns: 1fr; padding: 1rem; }
}

/* Sidebar */
.learn-sidebar-toggle-input { display: none; }
.learn-sidebar-toggle-btn {
  display: none; padding: .5rem .8rem; border: 1px solid #d6d3d1; border-radius: 6px;
  font-size: .85rem; cursor: pointer; background: #fff; margin-bottom: .75rem;
}
@media (max-width: 900px) { .learn-sidebar-toggle-btn { display: inline-block; } }

.learn-sidebar { position: sticky; top: 1.5rem; align-self: start; max-height: calc(100vh - 3rem); overflow-y: auto; }
@media (max-width: 900px) {
  .learn-sidebar { display: none; position: static; }
  .learn-sidebar-toggle-input:checked ~ .learn-sidebar { display: block; }
}
.learn-sidebar-inner { padding-right: .5rem; }
.learn-sidebar-group + .learn-sidebar-group { margin-top: 1.25rem; }
.learn-sidebar-group-title { font-size: .68rem; text-transform: uppercase; letter-spacing: .08em; color: #78716c; margin: 0 0 .55rem; font-weight: 700; }
.learn-sidebar-list { list-style: decimal; padding-left: 1.2rem; margin: 0; }
.learn-sidebar-list li { margin: .25rem 0; font-size: .88rem; }
.learn-sidebar-list li a { color: #1c1917; text-decoration: none; display: block; padding: .15rem .3rem; border-radius: 4px; }
.learn-sidebar-list li a:hover { background: #f5f5f4; }
.learn-sidebar-list li.active a { color: var(--accent, #f59e0b); font-weight: 600; background: #fffbeb; }

/* Lesson header */
.lesson-header { margin-bottom: 2rem; }
.lesson-crumb { font-size: .78rem; color: #78716c; margin-bottom: .35rem; }
.lesson-crumb a { color: var(--accent, #f59e0b); text-decoration: none; }
.lesson-title { font-size: 2.25rem; font-weight: 800; letter-spacing: -.03em; line-height: 1.15; margin: 0 0 .25rem; }
.lesson-subtitle { color: #57534e; font-size: 1.05rem; margin: 0 0 1rem; }
.lesson-chips { display: flex; gap: .6rem; margin-top: .75rem; }
.lesson-chip { display: inline-block; padding: .35rem .8rem; border: 1px solid #e7e5e4; border-radius: 999px; font-size: .82rem; color: #44403c; text-decoration: none; background: #fff; }
.lesson-chip:hover { border-color: var(--accent, #f59e0b); color: var(--accent, #f59e0b); }
.lesson-chip-next { margin-left: auto; }

/* You will learn */
.youwilllearn { background: #fffbeb; border-left: 4px solid var(--accent, #f59e0b); padding: 1rem 1.2rem; border-radius: 0 6px 6px 0; margin: 1.25rem 0; }
.youwilllearn h3 { margin: 0 0 .5rem; font-size: .92rem; text-transform: uppercase; letter-spacing: .05em; color: #92400e; }
.youwilllearn ul { margin: 0; padding-left: 1.25rem; }
.youwilllearn li { margin: .25rem 0; font-size: .92rem; }

/* Callout */
.callout { margin: 1rem 0; padding: .9rem 1.1rem; border-radius: 6px; border-left: 4px solid #e7e5e4; background: #fafaf9; font-size: .92rem; }
.callout-info { border-left-color: #2563eb; background: #eff6ff; }
.callout-warn { border-left-color: #d97706; background: #fffbeb; }
.callout-success { border-left-color: #059669; background: #ecfdf5; }
.callout-deep { border-left-color: #7c3aed; background: #f5f3ff; }
.callout-head { font-weight: 600; margin-bottom: .35rem; }
.callout-icon { margin-right: .3rem; }

/* Deep dive */
.deepdive { margin: 1.25rem 0; border: 1px solid #e7e5e4; border-radius: 6px; padding: .75rem 1rem; background: #fff; }
.deepdive summary { cursor: pointer; font-weight: 600; font-size: .92rem; color: #44403c; user-select: none; }
.deepdive[open] summary { margin-bottom: .65rem; }
.deepdive-icon { margin-right: .35rem; }

/* Try it */
.tryit { margin: 1.25rem 0; border: 2px solid var(--accent, #f59e0b); border-radius: 8px; overflow: hidden; }
.tryit-head { padding: .55rem .9rem; background: #fffbeb; font-weight: 600; font-size: .88rem; color: #92400e; border-bottom: 1px solid #fde68a; }
.tryit-icon { margin-right: .35rem; }
.tryit-body { padding: 1rem; }

/* Lesson body */
.lesson-content { min-width: 0; }
.lesson-content h2 { margin-top: 2.25rem; font-size: 1.55rem; font-weight: 700; letter-spacing: -.02em; }
.lesson-content h3 { margin-top: 1.5rem; font-size: 1.15rem; font-weight: 700; }
.lesson-content p { line-height: 1.65; color: #292524; }
.lesson-content code { background: #f5f5f4; padding: .1rem .35rem; border-radius: 3px; font-size: .92em; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
.lesson-content pre { background: #1c1917; color: #f5f5f4; padding: 1rem; border-radius: 6px; overflow-x: auto; font-size: .85rem; }
.lesson-content pre code { background: transparent; padding: 0; color: inherit; }

/* Notes app */
.notes-app { display: grid; grid-template-columns: 1fr; gap: 1rem; }
.notes-list { display: flex; flex-direction: column; gap: .75rem; }
.note { border: 1px solid #e7e5e4; border-radius: 6px; padding: .85rem 1rem; background: #fff; }
.note-title { margin: 0 0 .35rem; font-size: 1rem; font-weight: 600; }
.note-body { margin: 0; color: #44403c; font-size: .92rem; white-space: pre-wrap; }
.note-meta { font-size: .72rem; color: #78716c; margin-top: .5rem; display: flex; gap: .5rem; align-items: center; }
.note-meta button { background: transparent; border: 1px solid #e7e5e4; color: #b91c1c; padding: .15rem .55rem; border-radius: 4px; font-size: .72rem; cursor: pointer; }
.note-meta button:hover { background: #fef2f2; border-color: #fecaca; }

.note-form { display: grid; gap: .55rem; margin: 0 0 1rem; }
.note-form input, .note-form textarea {
  font: inherit; padding: .55rem .7rem; border: 1px solid #d6d3d1; border-radius: 6px; background: #fff;
}
.note-form textarea { min-height: 80px; resize: vertical; }
.note-form button { padding: .55rem 1rem; border: 0; border-radius: 6px; background: var(--accent, #f59e0b); color: #fff; font-weight: 600; cursor: pointer; }

/* Auth */
.auth-card { max-width: 420px; margin: 1.5rem 0; padding: 1.5rem; border: 1px solid #e7e5e4; border-radius: 8px; background: #fff; }
.auth-card h2 { margin: 0 0 .25rem; font-size: 1.25rem; }
.auth-card p { color: #78716c; margin: 0 0 1rem; font-size: .9rem; }
.auth-card form { display: grid; gap: .55rem; }
.auth-card input { padding: .55rem .7rem; border: 1px solid #d6d3d1; border-radius: 6px; font: inherit; }
.auth-card button { padding: .55rem 1rem; border: 0; border-radius: 6px; background: var(--accent, #f59e0b); color: #fff; font-weight: 600; cursor: pointer; }
.auth-card .auth-toggle { background: transparent; color: var(--accent, #f59e0b); border: 1px solid var(--accent, #f59e0b); }
.auth-error { color: #b91c1c; font-size: .85rem; margin-top: .5rem; }

/* Chat */
.chat { display: grid; grid-template-columns: 40% 60%; gap: 1rem; align-items: start; }
@media (max-width: 760px) { .chat { grid-template-columns: 1fr; } }
.chat-box { border: 1px solid #e7e5e4; border-radius: 8px; display: flex; flex-direction: column; background: #fff; height: 540px; }
.chat-head { padding: .65rem 1rem; border-bottom: 1px solid #f5f5f4; display: flex; align-items: center; gap: .5rem; font-size: .85rem; }
.chat-mode { font-size: .7rem; padding: .15rem .5rem; border-radius: 999px; background: #f5f5f4; color: #57534e; }
.chat-messages { flex: 1; overflow-y: auto; padding: 1rem; display: flex; flex-direction: column; gap: .75rem; }
.chat-msg { max-width: 88%; }
.chat-msg.user { align-self: flex-end; }
.chat-msg.assistant { align-self: flex-start; }
.chat-bubble { padding: .65rem .9rem; border-radius: 12px; font-size: .92rem; line-height: 1.55; }
.chat-msg.user .chat-bubble { background: var(--accent, #f59e0b); color: #fff; border-bottom-right-radius: 4px; }
.chat-msg.assistant .chat-bubble { background: #f5f5f4; color: #1c1917; border-bottom-left-radius: 4px; }
.chat-item { margin: .35rem 0; }
.chat-item.text p { margin: .25rem 0; }
.chat-item.tool { border: 1px solid #e7e5e4; border-radius: 6px; padding: .55rem .7rem; background: #fff; font-size: .82rem; }
.chat-item.tool[data-status="running"] .tool-status::before { content: "…"; animation: chat-pulse 1.2s infinite; }
.chat-item.tool[data-status="ok"] { border-color: #bbf7d0; background: #f0fdf4; }
.chat-item.tool[data-status="error"] { border-color: #fecaca; background: #fef2f2; }
.tool-head { display: flex; align-items: center; gap: .5rem; }
.tool-name { font-family: ui-monospace, monospace; font-weight: 600; }
.tool-status { margin-left: auto; font-size: .7rem; color: #78716c; }
.tool-detail { margin-top: .4rem; }
.tool-detail summary { cursor: pointer; font-size: .72rem; color: #57534e; }
.tool-args, .tool-result { margin: .35rem 0 0; padding: .35rem .55rem; background: #fafaf9; border-radius: 4px; font-size: .72rem; white-space: pre-wrap; word-break: break-all; }

@keyframes chat-pulse { 0%, 100% { opacity: 1; } 50% { opacity: .3; } }

.chat-form { display: flex; gap: .5rem; padding: .65rem; border-top: 1px solid #f5f5f4; }
.chat-form input { flex: 1; padding: .55rem .7rem; border: 1px solid #d6d3d1; border-radius: 6px; font: inherit; }
.chat-form button { padding: .55rem 1rem; border: 0; border-radius: 6px; background: var(--accent, #f59e0b); color: #fff; font-weight: 600; cursor: pointer; }
.chat-form button:disabled { opacity: .5; cursor: not-allowed; }
```

- [ ] **Step 2: Commit**

```bash
git add public/css/learn.css
git commit -m "feat(learn): baseline CSS — layout, sidebar, lesson, chat, notes"
```

### Task 1.5: Create the 12 lesson placeholder pages (public/ + template/pages/)

For each lesson we create two files: a 3-line `public/learn/<slug>.php` entry that calls `App::render('_master', …)`, and a `template/pages/learn/<slug>.php` body that includes the sidebar and a placeholder title. Content is filled in later milestones.

- [ ] **Step 1: Write `public/learn.php`**

```php
<?php use ZealPHP\App;
App::render('_master', [
    'title'       => 'ZealPHP · Learn',
    'page'        => 'learn',
    'active'      => 'learn',
    'description' => 'Learn ZealPHP by building a real personal-notes app with AI chat — server-rendered, PHP-native, no React tax.',
]);
```

- [ ] **Step 2: Write 11 sub-page entries**

For each of the following slugs (`create-app`, `first-page`, `components`, `routing`, `sessions`, `htmx`, `notes`, `ai-chat`, `async`, `deployment`, `philosophy`), write `public/learn/<slug>.php`:

```php
<?php use ZealPHP\App;
App::render('_master', [
    'title'  => 'ZealPHP Learn · <SLUG>',
    'page'   => 'learn/<slug>',
    'active' => 'learn/<slug>',
]);
```

Concrete files (each ~5 lines, identical pattern, replace `<slug>` and the title's `<SLUG>` token with the readable title for that lesson — see the Spec section B table for titles).

- [ ] **Step 3: Write a shared placeholder template `template/pages/learn.php`**

```php
<?php use ZealPHP\App; $active = $active ?? 'learn'; ?>
<div class="learn-layout">
  <?php App::render('/_learn_sidebar', ['active' => $active]); ?>
  <article class="lesson-content">
    <?php App::render('/components/_lesson_header', [
      'number'   => 1,
      'title'    => 'Quick Start',
      'subtitle' => 'What ZealPHP is, in one paragraph — and why you would build with it.',
      'next'     => ['slug' => 'learn/create-app', 'title' => 'Create a ZealPHP App'],
    ]); ?>
    <p>Lesson content coming in milestone 5.</p>
  </article>
</div>
```

- [ ] **Step 4: Write 11 placeholder lesson body templates**

For each slug, write `template/pages/learn/<slug>.php`:

```php
<?php use ZealPHP\App; $active = $active ?? 'learn/<slug>'; ?>
<div class="learn-layout">
  <?php App::render('/_learn_sidebar', ['active' => $active]); ?>
  <article class="lesson-content">
    <?php App::render('/components/_lesson_header', [
      'number'   => <N>,
      'title'    => '<TITLE>',
      'subtitle' => 'Placeholder — content lands in milestone 5/6/8.',
      'prev'     => ['slug' => '<prev-slug>', 'title' => '<prev-title>'],
      'next'     => ['slug' => '<next-slug>', 'title' => '<next-title>'],
    ]); ?>
    <p>Lesson content coming soon.</p>
  </article>
</div>
```

Numbering and prev/next chain (use these exact values; the last lesson has no `next`, the first sub-page has no `prev` on the index):

| N | slug | title | prev | next |
|---|---|---|---|---|
| 2 | create-app | Create a ZealPHP App | learn (Quick Start) | learn/first-page |
| 3 | first-page | Your First Page | learn/create-app | learn/components |
| 4 | components | Components | learn/first-page | learn/routing |
| 5 | routing | Routing | learn/components | learn/sessions |
| 6 | sessions | Sessions & Auth | learn/routing | learn/htmx |
| 7 | htmx | Add htmx | learn/sessions | learn/notes |
| 8 | notes | Build Personal Notes | learn/htmx | learn/ai-chat |
| 9 | ai-chat | Add AI Chat | learn/notes | learn/async |
| 10 | async | Async & Coroutines | learn/ai-chat | learn/deployment |
| 11 | deployment | Deployment | learn/async | learn/philosophy |
| 12 | philosophy | Philosophy | learn/deployment | — (omit `next`) |

- [ ] **Step 5: Verify all 12 pages render**

Start the server:

```bash
php app.php start -p 8090 -d --pid-file /tmp/zealphp/learn_dev.pid
sleep 2
for slug in '' '/create-app' '/first-page' '/components' '/routing' '/sessions' '/htmx' '/notes' '/ai-chat' '/async' '/deployment' '/philosophy'; do
  code=$(curl -s -o /dev/null -w "%{http_code}" "http://127.0.0.1:8090/learn$slug")
  echo "/learn$slug -> $code"
done
php app.php stop -p 8090 --pid-file /tmp/zealphp/learn_dev.pid
```

Expected: all 12 lines print `200`.

- [ ] **Step 6: Commit**

```bash
git add public/learn.php public/learn/ template/pages/learn.php template/pages/learn/
git commit -m "feat(learn): 12 lesson placeholder pages with sidebar + headers"
```

### Task 1.6: Visual verification with Chrome DevTools

- [ ] **Step 1: Start server in background**

```bash
php app.php start -p 8090 -d --pid-file /tmp/zealphp/learn_dev.pid
sleep 2
```

- [ ] **Step 2: Open Chrome DevTools, take a screenshot of `/learn`**

Use `mcp__chrome-devtools__new_page` with URL `http://127.0.0.1:8090/learn`, then `mcp__chrome-devtools__take_screenshot`. Confirm visually: top nav has "Learn" item highlighted, sidebar has 12 items in 3 groups, lesson header shows "Quick Start".

- [ ] **Step 3: Navigate to `/learn/components`, screenshot**

Use `mcp__chrome-devtools__navigate_page`. Confirm: sidebar still highlights the correct active item ("Components"), top nav "Learn" still highlighted.

- [ ] **Step 4: Test mobile layout**

Use `mcp__chrome-devtools__resize_page` with width=640. Take screenshot. Confirm: sidebar is hidden, "☰ Lessons" toggle is visible.

- [ ] **Step 5: Check console for errors**

Use `mcp__chrome-devtools__list_console_messages`. Confirm: no errors. (Warnings about htmx loading from CDN are acceptable.)

- [ ] **Step 6: Stop server**

```bash
php app.php stop -p 8090 --pid-file /tmp/zealphp/learn_dev.pid
```

- [ ] **Step 7: No commit** (verification only)

---

## Milestone 2 — Auth + SQLite bootstrap (TDD)

### Task 2.1: Write the failing auth helper tests

**Files:**
- Create: `tests/Unit/LearnAuthTest.php`

- [ ] **Step 1: Create the test file**

Write `/var/labsstorage/home/sibidharan/zealphp/tests/Unit/LearnAuthTest.php`:

```php
<?php
namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../route/learn.php';

class LearnAuthTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/learn_test_' . uniqid() . '.db';
        putenv('ZEALPHP_LEARN_DB_PATH=' . $this->dbPath);
    }

    protected function tearDown(): void
    {
        foreach ([$this->dbPath, $this->dbPath . '-wal', $this->dbPath . '-shm'] as $p) {
            if (file_exists($p)) @unlink($p);
        }
    }

    public function test_validate_username_accepts_valid(): void
    {
        $this->assertTrue(\learn_validate_username('alice'));
        $this->assertTrue(\learn_validate_username('alice_99'));
        $this->assertTrue(\learn_validate_username(str_repeat('a', 64)));
    }

    public function test_validate_username_rejects_invalid(): void
    {
        $this->assertFalse(\learn_validate_username('ab'));
        $this->assertFalse(\learn_validate_username(str_repeat('a', 65)));
        $this->assertFalse(\learn_validate_username('alice bob'));
        $this->assertFalse(\learn_validate_username('alice-bob'));
        $this->assertFalse(\learn_validate_username('alice!'));
    }

    public function test_validate_password_length(): void
    {
        $this->assertTrue(\learn_validate_password(str_repeat('x', 8)));
        $this->assertTrue(\learn_validate_password(str_repeat('x', 256)));
        $this->assertFalse(\learn_validate_password(str_repeat('x', 7)));
        $this->assertFalse(\learn_validate_password(str_repeat('x', 257)));
    }

    public function test_db_bootstrap_is_idempotent(): void
    {
        $db1 = \learn_db_open();
        $db2 = \learn_db_open();
        $this->assertInstanceOf(\PDO::class, $db1);
        $tables = $db1->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertContains('users', $tables);
        $this->assertContains('notes', $tables);
    }

    public function test_register_and_login_roundtrip(): void
    {
        $db = \learn_db_open();
        $userId = \learn_register_user($db, 'alice', 'password123');
        $this->assertIsInt($userId);
        $this->assertGreaterThan(0, $userId);

        $loggedInId = \learn_login_user($db, 'alice', 'password123');
        $this->assertSame($userId, $loggedInId);

        $this->assertNull(\learn_login_user($db, 'alice', 'wrong'));
        $this->assertNull(\learn_login_user($db, 'nope', 'password123'));
    }

    public function test_register_duplicate_username_returns_null(): void
    {
        $db = \learn_db_open();
        \learn_register_user($db, 'alice', 'password123');
        $this->assertNull(\learn_register_user($db, 'alice', 'differentpw99'));
    }
}
```

- [ ] **Step 2: Run tests, confirm they fail**

```bash
./vendor/bin/phpunit tests/Unit/LearnAuthTest.php
```

Expected: failures on `require route/learn.php` (file doesn't exist yet) or on `Call to undefined function learn_validate_username`.

### Task 2.2: Create the DB bootstrap + auth helpers to make tests pass

**Files:**
- Create: `route/learn.php`

- [ ] **Step 1: Write the helper-only first cut of `route/learn.php`**

Write the file with this content. Note: DDL statements use `->query()` (works for CREATE TABLE since DDL returns no rows in SQLite); prepared statements use `->execute([...])` which is the only standard PDO API.

```php
<?php
// route/learn.php — /learn API endpoints + SQLite + auth helpers.

use ZealPHP\App;
use ZealPHP\G;

if (!function_exists('learn_db_path')) {
    function learn_db_path(): string {
        $configured = getenv('ZEALPHP_LEARN_DB_PATH');
        if ($configured === false || $configured === '') $configured = 'storage/learn.db';
        if ($configured[0] !== '/') {
            $root = defined('ZEALPHP_ROOT') ? ZEALPHP_ROOT : __DIR__ . '/..';
            $configured = $root . '/' . $configured;
        }
        $dir = dirname($configured);
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        return $configured;
    }
}

if (!function_exists('learn_db_open')) {
    function learn_db_open(): \PDO {
        static $cache = [];
        $path = learn_db_path();
        if (isset($cache[$path])) return $cache[$path];
        $pdo = new \PDO('sqlite:' . $path);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $pdo->query('PRAGMA journal_mode = WAL');
        $pdo->query('PRAGMA foreign_keys = ON');
        $pdo->query('PRAGMA busy_timeout = 2000');
        $pdo->query("CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT UNIQUE NOT NULL, password_hash TEXT NOT NULL, created_at INTEGER NOT NULL)");
        $pdo->query("CREATE TABLE IF NOT EXISTS notes (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, title TEXT NOT NULL, body TEXT NOT NULL, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL)");
        $pdo->query("CREATE INDEX IF NOT EXISTS idx_notes_user_updated ON notes(user_id, updated_at DESC)");
        $cache[$path] = $pdo;
        return $pdo;
    }
}

if (!function_exists('learn_validate_username')) {
    function learn_validate_username(string $u): bool {
        return (bool)preg_match('/^[A-Za-z0-9_]{3,64}$/', $u);
    }
}

if (!function_exists('learn_validate_password')) {
    function learn_validate_password(string $p): bool {
        $len = strlen($p);
        return $len >= 8 && $len <= 256;
    }
}

if (!function_exists('learn_register_user')) {
    function learn_register_user(\PDO $db, string $username, string $password): ?int {
        if (!learn_validate_username($username) || !learn_validate_password($password)) return null;
        try {
            $stmt = $db->prepare('INSERT INTO users (username, password_hash, created_at) VALUES (?, ?, ?)');
            $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), time()]);
            return (int)$db->lastInsertId();
        } catch (\PDOException $e) {
            return null;
        }
    }
}

if (!function_exists('learn_login_user')) {
    function learn_login_user(\PDO $db, string $username, string $password): ?int {
        $row = $db->prepare('SELECT id, password_hash FROM users WHERE username = ?');
        $row->execute([$username]);
        $user = $row->fetch();
        if (!$user) return null;
        if (!password_verify($password, $user['password_hash'])) return null;
        return (int)$user['id'];
    }
}

// Endpoint registrations follow (added in Task 2.3, 3.x, 6.x).
if (class_exists('ZealPHP\\App', false) && !defined('ZEALPHP_LEARN_TESTING')) {
    // ...
}
```

- [ ] **Step 2: Run tests, confirm they pass**

```bash
./vendor/bin/phpunit tests/Unit/LearnAuthTest.php --testdox
```

Expected: all 6 tests pass.

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/LearnAuthTest.php route/learn.php
git commit -m "feat(learn): SQLite bootstrap + auth helpers (TDD: 6 unit tests pass)"
```

### Task 2.3: Wire register/login/logout endpoints

**Files:**
- Modify: `route/learn.php` — replace the placeholder block at the bottom

- [ ] **Step 1: Replace the placeholder block**

Find this in `route/learn.php`:

```php
// Endpoint registrations follow (added in Task 2.3, 3.x, 6.x).
if (class_exists('ZealPHP\\App', false) && !defined('ZEALPHP_LEARN_TESTING')) {
    // ...
}
```

Replace with the endpoint registrations. The full replacement block is provided in `docs/learn-app.md` (created in Task 11.1) — copy from there. For now, here is the canonical code:

```php
// ── Endpoint registrations ───────────────────────────────────────────
$app = App::instance();

\ZealPHP\Store::make('learn_login_rl', 1024, [
    'ip'    => [\OpenSwoole\Table::TYPE_STRING, 45],
    'count' => [\OpenSwoole\Table::TYPE_INT, 4],
    'reset' => [\OpenSwoole\Table::TYPE_INT, 4],
]);
\ZealPHP\Store::make('learn_register_rl', 1024, [
    'ip'    => [\OpenSwoole\Table::TYPE_STRING, 45],
    'count' => [\OpenSwoole\Table::TYPE_INT, 4],
    'reset' => [\OpenSwoole\Table::TYPE_INT, 4],
]);

function learn_rate_limit(string $table, string $ip, int $limit, int $window): bool {
    $now = time();
    $existing = \ZealPHP\Store::get($table, $ip);
    if ($existing && $now < $existing['reset']) {
        if ($existing['count'] >= $limit) return false;
        \ZealPHP\Store::incr($table, $ip, 'count', 1);
        return true;
    }
    \ZealPHP\Store::set($table, $ip, ['ip' => $ip, 'count' => 1, 'reset' => $now + $window]);
    return true;
}

function learn_read_credentials($g): ?array {
    $ct = $g->server['HTTP_CONTENT_TYPE'] ?? $g->server['CONTENT_TYPE'] ?? '';
    if (stripos($ct, 'application/json') !== false) {
        $body = json_decode($g->zealphp_request->parent->getContent(), true);
        if (!is_array($body)) return null;
        $u = (string)($body['username'] ?? '');
        $p = (string)($body['password'] ?? '');
    } else {
        $u = (string)($g->post['username'] ?? '');
        $p = (string)($g->post['password'] ?? '');
    }
    if ($u === '' || $p === '') return null;
    return ['username' => $u, 'password' => $p];
}

function learn_current_user(): ?array {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (empty($_SESSION['user_id'])) return null;
    return ['user_id' => (int)$_SESSION['user_id'], 'username' => (string)$_SESSION['username']];
}

$app->route('/api/learn/register', ['methods' => ['POST']], function($request, $response) {
    $g = G::instance();
    $ip = $g->server['REMOTE_ADDR'] ?? 'unknown';
    if (!learn_rate_limit('learn_register_rl', $ip, 5, 300)) {
        http_response_code(429); header('Content-Type: application/json');
        return ['error' => 'rate_limit'];
    }
    $creds = learn_read_credentials($g);
    if (!$creds) { http_response_code(422); header('Content-Type: application/json'); return ['error' => 'validation_failed']; }
    if (!learn_validate_username($creds['username'])) { http_response_code(422); header('Content-Type: application/json'); return ['error' => 'invalid_username']; }
    if (!learn_validate_password($creds['password'])) { http_response_code(422); header('Content-Type: application/json'); return ['error' => 'invalid_password']; }

    $db = learn_db_open();
    $userId = learn_register_user($db, $creds['username'], $creds['password']);
    if ($userId === null) { http_response_code(409); header('Content-Type: application/json'); return ['error' => 'username_taken']; }

    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $_SESSION['user_id'] = $userId;
    $_SESSION['username'] = $creds['username'];
    $response->redirect('/learn/notes', 302);
});

$app->route('/api/learn/login', ['methods' => ['POST']], function($request, $response) {
    $g = G::instance();
    $ip = $g->server['REMOTE_ADDR'] ?? 'unknown';
    if (!learn_rate_limit('learn_login_rl', $ip, 10, 300)) {
        http_response_code(429); header('Content-Type: application/json');
        return ['error' => 'rate_limit'];
    }
    $creds = learn_read_credentials($g);
    if (!$creds) { http_response_code(422); header('Content-Type: application/json'); return ['error' => 'validation_failed']; }

    $db = learn_db_open();
    $userId = learn_login_user($db, $creds['username'], $creds['password']);
    if ($userId === null) { http_response_code(401); header('Content-Type: application/json'); return ['error' => 'invalid_credentials']; }

    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $_SESSION['user_id'] = $userId;
    $_SESSION['username'] = $creds['username'];
    $response->redirect('/learn/notes', 302);
});

$app->route('/api/learn/logout', ['methods' => ['POST', 'GET']], function($request, $response) {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $_SESSION = [];
    session_destroy();
    $response->redirect('/learn/notes', 302);
});
```

- [ ] **Step 2: Lint check**

```bash
php -l route/learn.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3: Smoke-test register + login + logout via curl**

```bash
php app.php start -p 8090 -d --pid-file /tmp/zealphp/learn_dev.pid
sleep 2

curl -s -c /tmp/learn_cookies.txt -o /dev/null -w "register: %{http_code}\n" \
  -H "Content-Type: application/json" \
  -d '{"username":"alice","password":"password123"}' \
  http://127.0.0.1:8090/api/learn/register

curl -s -b /tmp/learn_cookies.txt -c /tmp/learn_cookies.txt -o /dev/null -w "logout: %{http_code}\n" \
  -X POST http://127.0.0.1:8090/api/learn/logout

curl -s -b /tmp/learn_cookies.txt -c /tmp/learn_cookies.txt -o /dev/null -w "login: %{http_code}\n" \
  -H "Content-Type: application/json" \
  -d '{"username":"alice","password":"password123"}' \
  http://127.0.0.1:8090/api/learn/login

curl -s -o /dev/null -w "wrongpw: %{http_code}\n" \
  -H "Content-Type: application/json" \
  -d '{"username":"alice","password":"WRONG"}' \
  http://127.0.0.1:8090/api/learn/login

curl -s -o /dev/null -w "dup: %{http_code}\n" \
  -H "Content-Type: application/json" \
  -d '{"username":"alice","password":"password123"}' \
  http://127.0.0.1:8090/api/learn/register

php app.php stop -p 8090 --pid-file /tmp/zealphp/learn_dev.pid
```

Expected output:
```
register: 302
logout: 302
login: 302
wrongpw: 401
dup: 409
```

- [ ] **Step 4: Clean test DB before commit**

```bash
rm -f storage/learn.db storage/learn.db-wal storage/learn.db-shm /tmp/learn_cookies.txt
```

- [ ] **Step 5: Commit**

```bash
git add route/learn.php
git commit -m "feat(learn): register/login/logout endpoints with rate limits"
```

---

## Milestone 3 — Notes CRUD (TDD)

### Task 3.1: Write failing notes-repo unit tests

**Files:**
- Create: `tests/Unit/LearnNotesRepoTest.php`

- [ ] **Step 1: Write the test file**

```php
<?php
namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../route/learn.php';

class LearnNotesRepoTest extends TestCase
{
    private string $dbPath;
    private \PDO $db;
    private int $aliceId;
    private int $bobId;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/learn_test_' . uniqid() . '.db';
        putenv('ZEALPHP_LEARN_DB_PATH=' . $this->dbPath);
        $this->db = \learn_db_open();
        $this->aliceId = \learn_register_user($this->db, 'alice', 'password123');
        $this->bobId = \learn_register_user($this->db, 'bob', 'password123');
    }

    protected function tearDown(): void
    {
        foreach ([$this->dbPath, $this->dbPath . '-wal', $this->dbPath . '-shm'] as $p) {
            if (file_exists($p)) @unlink($p);
        }
    }

    public function test_create_and_list_notes(): void
    {
        $id = \learn_notes_create($this->db, $this->aliceId, 'Buy milk', 'Whole, not skim');
        $this->assertIsInt($id);
        $notes = \learn_notes_list($this->db, $this->aliceId);
        $this->assertCount(1, $notes);
        $this->assertSame('Buy milk', $notes[0]['title']);
    }

    public function test_user_isolation(): void
    {
        \learn_notes_create($this->db, $this->aliceId, 'Alice note', '');
        \learn_notes_create($this->db, $this->bobId, 'Bob note', '');
        $this->assertCount(1, \learn_notes_list($this->db, $this->aliceId));
        $this->assertCount(1, \learn_notes_list($this->db, $this->bobId));
        $this->assertSame('Alice note', \learn_notes_list($this->db, $this->aliceId)[0]['title']);
    }

    public function test_update_scoped_to_user(): void
    {
        $id = \learn_notes_create($this->db, $this->aliceId, 'orig', 'body');
        $this->assertTrue(\learn_notes_update($this->db, $this->aliceId, $id, 'new', null));
        $note = \learn_notes_read($this->db, $this->aliceId, $id);
        $this->assertSame('new', $note['title']);
        $this->assertSame('body', $note['body']);
        // Bob cannot update Alice's note
        $this->assertFalse(\learn_notes_update($this->db, $this->bobId, $id, 'hacked', null));
    }

    public function test_delete_scoped_to_user(): void
    {
        $id = \learn_notes_create($this->db, $this->aliceId, 't', 'b');
        $this->assertFalse(\learn_notes_delete($this->db, $this->bobId, $id));
        $this->assertCount(1, \learn_notes_list($this->db, $this->aliceId));
        $this->assertTrue(\learn_notes_delete($this->db, $this->aliceId, $id));
        $this->assertCount(0, \learn_notes_list($this->db, $this->aliceId));
    }

    public function test_title_length_limit(): void
    {
        $this->assertNull(\learn_notes_create($this->db, $this->aliceId, str_repeat('a', 201), ''));
    }

    public function test_body_length_limit(): void
    {
        $this->assertNull(\learn_notes_create($this->db, $this->aliceId, 't', str_repeat('a', 4097)));
    }

    public function test_search_notes(): void
    {
        \learn_notes_create($this->db, $this->aliceId, 'Buy groceries', 'Apples and bread');
        \learn_notes_create($this->db, $this->aliceId, 'Pay rent', 'Due Friday');
        \learn_notes_create($this->db, $this->bobId,   'Bob groceries', 'shopping');
        $hits = \learn_notes_search($this->db, $this->aliceId, 'groceries');
        $this->assertCount(1, $hits);
        $this->assertSame('Buy groceries', $hits[0]['title']);
    }
}
```

- [ ] **Step 2: Run, confirm fail**

```bash
./vendor/bin/phpunit tests/Unit/LearnNotesRepoTest.php
```

Expected: `Call to undefined function learn_notes_create` etc.

### Task 3.2: Add notes-repo helpers to route/learn.php

**Files:**
- Modify: `route/learn.php` — insert before the endpoint-registration block

- [ ] **Step 1: Append helpers**

Insert this block right after `learn_login_user` and before the endpoint-registration block (`$app = App::instance();`):

```php
if (!function_exists('learn_notes_create')) {
    function learn_notes_create(\PDO $db, int $userId, string $title, string $body): ?int {
        $title = trim($title);
        if ($title === '' || mb_strlen($title) > 200) return null;
        if (strlen($body) > 4096) return null;
        $max = (int)(getenv('ZEALPHP_LEARN_MAX_NOTES') ?: 256);
        $cnt = (int)$db->prepare('SELECT COUNT(*) FROM notes WHERE user_id = ?');
        $cnt->execute([$userId]);
        if ((int)$cnt->fetchColumn() >= $max) return null;
        $now = time();
        $stmt = $db->prepare('INSERT INTO notes (user_id, title, body, created_at, updated_at) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$userId, $title, $body, $now, $now]);
        return (int)$db->lastInsertId();
    }
}

if (!function_exists('learn_notes_list')) {
    function learn_notes_list(\PDO $db, int $userId): array {
        $stmt = $db->prepare('SELECT id, title, body, created_at, updated_at FROM notes WHERE user_id = ? ORDER BY updated_at DESC');
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }
}

if (!function_exists('learn_notes_read')) {
    function learn_notes_read(\PDO $db, int $userId, int $noteId): ?array {
        $stmt = $db->prepare('SELECT id, title, body, created_at, updated_at FROM notes WHERE id = ? AND user_id = ?');
        $stmt->execute([$noteId, $userId]);
        $r = $stmt->fetch();
        return $r ?: null;
    }
}

if (!function_exists('learn_notes_update')) {
    function learn_notes_update(\PDO $db, int $userId, int $noteId, ?string $title, ?string $body): bool {
        $existing = learn_notes_read($db, $userId, $noteId);
        if (!$existing) return false;
        $newTitle = $title ?? $existing['title'];
        $newBody  = $body  ?? $existing['body'];
        $newTitle = trim($newTitle);
        if ($newTitle === '' || mb_strlen($newTitle) > 200) return false;
        if (strlen($newBody) > 4096) return false;
        $stmt = $db->prepare('UPDATE notes SET title = ?, body = ?, updated_at = ? WHERE id = ? AND user_id = ?');
        $stmt->execute([$newTitle, $newBody, time(), $noteId, $userId]);
        return $stmt->rowCount() > 0;
    }
}

if (!function_exists('learn_notes_delete')) {
    function learn_notes_delete(\PDO $db, int $userId, int $noteId): bool {
        $stmt = $db->prepare('DELETE FROM notes WHERE id = ? AND user_id = ?');
        $stmt->execute([$noteId, $userId]);
        return $stmt->rowCount() > 0;
    }
}

if (!function_exists('learn_notes_search')) {
    function learn_notes_search(\PDO $db, int $userId, string $query, int $limit = 10): array {
        $q = '%' . $query . '%';
        $stmt = $db->prepare('SELECT id, title, body, updated_at FROM notes WHERE user_id = ? AND (title LIKE ? OR body LIKE ?) ORDER BY updated_at DESC LIMIT ?');
        $stmt->execute([$userId, $q, $q, $limit]);
        return $stmt->fetchAll();
    }
}
```

Also fix a bug in the test-fixture: in `learn_notes_create` above, `(int)$db->prepare(...)` is a typo for `$cnt = $db->prepare(...)`. Use this corrected version:

```php
        $cnt = $db->prepare('SELECT COUNT(*) FROM notes WHERE user_id = ?');
```

- [ ] **Step 2: Run tests, confirm pass**

```bash
./vendor/bin/phpunit tests/Unit/LearnNotesRepoTest.php --testdox
```

Expected: all 7 tests pass.

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/LearnNotesRepoTest.php route/learn.php
git commit -m "feat(learn): notes repo helpers — user-scoped CRUD + search (7 tests pass)"
```

### Task 3.3: Add notes API endpoints + `_note_card` component

**Files:**
- Modify: `route/learn.php`
- Create: `template/components/_note_card.php`

- [ ] **Step 1: Write `_note_card.php`**

```php
<?php
$id    = (int)($id ?? 0);
$title = (string)($title ?? '');
$body  = (string)($body ?? '');
$ts    = (int)($updated_at ?? time());
?>
<article class="note" id="note-<?= $id ?>" data-id="<?= $id ?>">
  <h4 class="note-title"><?= htmlspecialchars($title) ?></h4>
  <p class="note-body"><?= nl2br(htmlspecialchars($body)) ?></p>
  <div class="note-meta">
    <span>Updated <?= date('Y-m-d H:i', $ts) ?></span>
    <button hx-delete="/api/learn/notes/<?= $id ?>" hx-target="#note-<?= $id ?>" hx-swap="outerHTML" hx-confirm="Delete this note?">Delete</button>
  </div>
</article>
```

- [ ] **Step 2: Append notes endpoints to `route/learn.php`**

Add after the auth endpoints, before the closing block:

```php
$app->route('/api/learn/notes', ['methods' => ['GET']], function($request, $response) {
    $u = learn_current_user();
    if (!$u) { http_response_code(401); header('Content-Type: application/json'); return ['error' => 'auth_required']; }
    $db = learn_db_open();
    $notes = learn_notes_list($db, $u['user_id']);
    return (function() use ($notes) {
        if (empty($notes)) { yield '<p class="notes-empty">No notes yet. Add one above.</p>'; return; }
        foreach ($notes as $n) {
            yield App::renderToString('/components/_note_card', $n);
        }
    })();
});

$app->route('/api/learn/notes', ['methods' => ['POST']], function($request, $response) {
    $u = learn_current_user();
    if (!$u) { http_response_code(401); header('Content-Type: application/json'); return ['error' => 'auth_required']; }
    $g = G::instance();
    $ct = $g->server['HTTP_CONTENT_TYPE'] ?? '';
    if (stripos($ct, 'application/json') !== false) {
        $body = json_decode($g->zealphp_request->parent->getContent(), true) ?: [];
    } else {
        $body = $g->post;
    }
    $title = (string)($body['title'] ?? '');
    $bodyText = (string)($body['body'] ?? '');
    $db = learn_db_open();
    $id = learn_notes_create($db, $u['user_id'], $title, $bodyText);
    if ($id === null) { http_response_code(422); header('Content-Type: application/json'); return ['error' => 'validation_failed']; }
    $note = learn_notes_read($db, $u['user_id'], $id);
    header('Content-Type: text/html; charset=utf-8');
    return App::renderToString('/components/_note_card', $note);
});

$app->route('/api/learn/notes/{id:[0-9]+}', ['methods' => ['POST']], function($request, $response, $id) {
    $u = learn_current_user();
    if (!$u) { http_response_code(401); header('Content-Type: application/json'); return ['error' => 'auth_required']; }
    $g = G::instance();
    $body = json_decode($g->zealphp_request->parent->getContent(), true) ?: $g->post;
    $db = learn_db_open();
    $ok = learn_notes_update($db, $u['user_id'], (int)$id, $body['title'] ?? null, $body['body'] ?? null);
    if (!$ok) { http_response_code(404); header('Content-Type: application/json'); return ['error' => 'not_found']; }
    $note = learn_notes_read($db, $u['user_id'], (int)$id);
    header('Content-Type: text/html; charset=utf-8');
    return App::renderToString('/components/_note_card', $note);
});

$app->route('/api/learn/notes/{id:[0-9]+}', ['methods' => ['DELETE']], function($request, $response, $id) {
    $u = learn_current_user();
    if (!$u) { http_response_code(401); header('Content-Type: application/json'); return ['error' => 'auth_required']; }
    $db = learn_db_open();
    $ok = learn_notes_delete($db, $u['user_id'], (int)$id);
    if (!$ok) { http_response_code(404); return; }
    return '';
});
```

- [ ] **Step 3: Lint**

```bash
php -l route/learn.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 4: Smoke-test with curl**

```bash
php app.php start -p 8090 -d --pid-file /tmp/zealphp/learn_dev.pid
sleep 2

# Register a fresh user
curl -s -c /tmp/lc.txt -o /dev/null -w "register: %{http_code}\n" \
  -H "Content-Type: application/json" \
  -d '{"username":"carol","password":"password123"}' \
  http://127.0.0.1:8090/api/learn/register

# Create a note
curl -s -b /tmp/lc.txt -c /tmp/lc.txt -o /tmp/lo.txt -w "create: %{http_code}\n" \
  -H "Content-Type: application/json" \
  -d '{"title":"Test","body":"hello"}' \
  http://127.0.0.1:8090/api/learn/notes
grep -q 'class="note"' /tmp/lo.txt && echo "create-html: OK" || echo "create-html: FAIL"

# List
curl -s -b /tmp/lc.txt -o /tmp/lo.txt -w "list: %{http_code}\n" http://127.0.0.1:8090/api/learn/notes
grep -q '>Test<' /tmp/lo.txt && echo "list-html: OK" || echo "list-html: FAIL"

# Delete (get the id first via list grep)
NID=$(grep -oP 'data-id="\K\d+' /tmp/lo.txt | head -1)
curl -s -b /tmp/lc.txt -o /dev/null -w "delete: %{http_code}\n" \
  -X DELETE http://127.0.0.1:8090/api/learn/notes/$NID

# Unauth check
curl -s -o /dev/null -w "unauth-list: %{http_code}\n" http://127.0.0.1:8090/api/learn/notes

php app.php stop -p 8090 --pid-file /tmp/zealphp/learn_dev.pid
rm -f storage/learn.db storage/learn.db-wal storage/learn.db-shm /tmp/lc.txt /tmp/lo.txt
```

Expected:
```
register: 302
create: 200
create-html: OK
list: 200
list-html: OK
delete: 200
unauth-list: 401
```

- [ ] **Step 5: Commit**

```bash
git add route/learn.php template/components/_note_card.php
git commit -m "feat(learn): notes CRUD endpoints + _note_card component"
```

---

## Milestone 4 — htmx counter (Lesson 7 demo) + Lesson 8 page wiring

### Task 4.1: Counter demo endpoint + component

**Files:**
- Create: `template/components/_counter_button.php`
- Modify: `route/learn.php`

- [ ] **Step 1: Write `_counter_button.php`**

```php
<?php $n = (int)($n ?? 0); ?>
<button class="counter-btn"
        hx-post="/api/learn/demo/incr"
        hx-target="this"
        hx-swap="outerHTML">
  Clicked <strong><?= $n ?></strong> times
</button>
```

- [ ] **Step 2: Append `/api/learn/demo/incr` endpoint to `route/learn.php`**

```php
$app->route('/api/learn/demo/incr', ['methods' => ['POST', 'GET']], function($request, $response) {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $_SESSION['demo_counter'] = (int)($_SESSION['demo_counter'] ?? 0) + 1;
    header('Content-Type: text/html; charset=utf-8');
    return App::renderToString('/components/_counter_button', ['n' => $_SESSION['demo_counter']]);
});
```

- [ ] **Step 3: Smoke test**

```bash
php app.php start -p 8090 -d --pid-file /tmp/zealphp/learn_dev.pid
sleep 2
curl -s -c /tmp/cc.txt -X POST http://127.0.0.1:8090/api/learn/demo/incr | grep -o 'Clicked <strong>1</strong>'
curl -s -b /tmp/cc.txt -X POST http://127.0.0.1:8090/api/learn/demo/incr | grep -o 'Clicked <strong>2</strong>'
php app.php stop -p 8090 --pid-file /tmp/zealphp/learn_dev.pid
rm -f /tmp/cc.txt
```

Expected: `Clicked <strong>1</strong>` then `Clicked <strong>2</strong>`.

- [ ] **Step 4: Commit**

```bash
git add template/components/_counter_button.php route/learn.php
git commit -m "feat(learn): counter demo endpoint + button component for Lesson 7"
```

### Task 4.2: Lesson 8 (Notes) interactive page

**Files:**
- Modify: `template/pages/learn/notes.php`

- [ ] **Step 1: Replace the placeholder body with a real Notes app**

```php
<?php use ZealPHP\App;
$user = function_exists('learn_current_user') ? learn_current_user() : null;
?>
<div class="learn-layout">
  <?php App::render('/_learn_sidebar', ['active' => 'learn/notes']); ?>
  <article class="lesson-content">
    <?php App::render('/components/_lesson_header', [
      'number' => 8, 'title' => 'Build Personal Notes',
      'subtitle' => 'A real app — register, log in, save notes. Backed by SQLite, wired with htmx.',
      'prev' => ['slug' => 'learn/htmx', 'title' => 'Add htmx'],
      'next' => ['slug' => 'learn/ai-chat', 'title' => 'Add AI Chat'],
    ]); ?>

    <?php App::render('/components/_youwilllearn', ['items' => [
      'Open a SQLite database with PDO from a ZealPHP route',
      'Insert / select / update / delete notes scoped to the logged-in user',
      'Use htmx to add and remove notes without page reloads',
      'Use App::renderStream + renderToString to build server-rendered HTML fragments',
    ]]); ?>

    <?php if (!$user): ?>
      <section class="auth-card">
        <h2>Sign in to your vault</h2>
        <p>No email needed — just pick a username and password. Lost the password? Make a new account.</p>
        <form method="post" action="/api/learn/login">
          <input type="text" name="username" placeholder="username" autocomplete="username" required minlength="3" maxlength="64">
          <input type="password" name="password" placeholder="password (≥ 8 chars)" autocomplete="current-password" required minlength="8">
          <button type="submit">Log in</button>
        </form>
        <details style="margin-top:1rem">
          <summary>New here? Register</summary>
          <form method="post" action="/api/learn/register" style="margin-top:.75rem">
            <input type="text" name="username" placeholder="new username" required minlength="3" maxlength="64">
            <input type="password" name="password" placeholder="new password" required minlength="8">
            <button type="submit" class="auth-toggle">Register</button>
          </form>
        </details>
      </section>
    <?php else: ?>
      <p>Logged in as <strong><?= htmlspecialchars($user['username']) ?></strong> · <a href="/api/learn/logout">Log out</a></p>

      <section class="notes-app">
        <form class="note-form"
              hx-post="/api/learn/notes"
              hx-target="#notes-list"
              hx-swap="afterbegin"
              hx-on::after-request="this.reset()">
          <input type="text" name="title" placeholder="Note title" required maxlength="200">
          <textarea name="body" placeholder="Body (markdown OK)" maxlength="4096"></textarea>
          <button type="submit">Add note</button>
        </form>

        <div id="notes-list" class="notes-list"
             hx-get="/api/learn/notes"
             hx-trigger="load"
             hx-swap="innerHTML">
          <p class="notes-empty">Loading…</p>
        </div>
      </section>
    <?php endif; ?>

    <h2>How this works</h2>
    <p>Three files, ~200 lines total. Read the source for each below.</p>
    <ul>
      <li><code>route/learn.php</code> — register/login/logout + notes CRUD endpoints</li>
      <li><code>template/components/_note_card.php</code> — the per-note <code>&lt;article&gt;</code> template</li>
      <li><code>template/pages/learn/notes.php</code> — this page (the form + the htmx attributes)</li>
    </ul>
  </article>
</div>
```

- [ ] **Step 2: Visual verification with Chrome DevTools**

```bash
php app.php start -p 8090 -d --pid-file /tmp/zealphp/learn_dev.pid
sleep 2
```

Then in this assistant session:

1. `mcp__chrome-devtools__new_page` → `http://127.0.0.1:8090/learn/notes`
2. `mcp__chrome-devtools__take_screenshot` — should show login card (not logged in)
3. `mcp__chrome-devtools__fill_form` with the register form → click "Register"
4. `mcp__chrome-devtools__take_screenshot` — should show the empty notes list + form
5. Fill the add-note form, submit
6. `mcp__chrome-devtools__take_screenshot` — should show the new note as a card
7. Click "Delete" on the note, confirm dialog
8. `mcp__chrome-devtools__take_screenshot` — note gone
9. `mcp__chrome-devtools__list_console_messages` — no errors

Stop server:

```bash
php app.php stop -p 8090 --pid-file /tmp/zealphp/learn_dev.pid
```

- [ ] **Step 3: Commit**

```bash
git add template/pages/learn/notes.php
git commit -m "feat(learn): Lesson 8 — interactive Notes app page with auth gate + htmx"
```

### Task 4.3: Three render-method demo endpoints (for Lesson 4)

Three small API endpoints, each demonstrating one of ZealPHP's render methods. Each is callable as plain HTTP so the Components lesson can embed them in "Try it" panels — and so curl + Chrome DevTools can show the difference (especially `renderStream`'s incremental flush).

**Files:**
- Create: `template/components/_demo_clock.php`
- Modify: `route/learn.php` — append three endpoints

- [ ] **Step 1: Write the demo template**

A tiny template that takes a `$label` and a `$now` timestamp and renders one row. We reuse it across all three endpoints so the *output* is identical and the only difference is *how it was produced*.

```php
<?php
$label ??= 'row';
$now   ??= microtime(true);
?>
<div class="render-demo-row" data-label="<?= htmlspecialchars($label) ?>">
  <strong><?= htmlspecialchars($label) ?></strong>
  <time><?= number_format($now - (int)$now, 4) ?>s</time>
</div>
```

- [ ] **Step 2: Append the three endpoints to `route/learn.php`**

```php
// ── Lesson 4 render-method demos ─────────────────────────────────────
// All three produce visually-similar output. The teaching is in the
// HTTP behavior: render() / renderToString() return all at once,
// renderStream() flushes chunks as the Generator yields.

$app->route('/api/learn/demo/render', ['methods' => ['GET']], function() {
    header('Content-Type: text/html; charset=utf-8');
    header('X-Render-Method: App::render');
    // App::render echoes directly inside the handler. The output buffer
    // is captured by ResponseMiddleware and returned to the client.
    App::render('/components/_demo_clock', ['label' => 'render() — echoed', 'now' => microtime(true)]);
});

$app->route('/api/learn/demo/render-to-string', ['methods' => ['GET']], function() {
    header('Content-Type: text/html; charset=utf-8');
    header('X-Render-Method: App::renderToString');
    // renderToString returns the rendered HTML so we can compose it
    // into a larger response. Useful for htmx fragments and email bodies.
    $card = App::renderToString('/components/_demo_clock', [
        'label' => 'renderToString() — composed',
        'now'   => microtime(true),
    ]);
    return "<section class=\"render-demo\"><h4>Composed wrapper</h4>{$card}</section>";
});

$app->route('/api/learn/demo/render-stream', ['methods' => ['GET']], function($request, $response) {
    header('Content-Type: text/html; charset=utf-8');
    header('X-Render-Method: App::renderStream');
    // renderStream returns a Generator. Each `yield` is flushed to the
    // client immediately. Sleep between yields so the streaming is visible.
    return (function() {
        yield "<section class=\"render-demo\"><h4>Streamed rows</h4>";
        for ($i = 1; $i <= 5; $i++) {
            \OpenSwoole\Coroutine::sleep(0.25);
            yield from App::renderStream('/components/_demo_clock', [
                'label' => "renderStream() — row {$i}",
                'now'   => microtime(true),
            ]);
        }
        yield "</section>";
    })();
});
```

- [ ] **Step 3: Smoke-test with curl — confirm streaming behaviour**

```bash
php app.php start -p 8090 -d --pid-file /tmp/zealphp/learn_dev.pid
sleep 2

echo "--- render (single-shot) ---"
curl -s -D - http://127.0.0.1:8090/api/learn/demo/render | head -8

echo "--- renderToString (single-shot, wrapped) ---"
curl -s -D - http://127.0.0.1:8090/api/learn/demo/render-to-string | head -8

echo "--- renderStream (5 chunks over ~1.25s) ---"
# -N disables buffering so we can SEE chunks arriving over time.
time curl -sN http://127.0.0.1:8090/api/learn/demo/render-stream

php app.php stop -p 8090 --pid-file /tmp/zealphp/learn_dev.pid
```

Expected:
- First two responses return the full HTML in milliseconds; `X-Render-Method` header is set on each.
- The third response takes ~1.25 seconds total (5 × 0.25s sleeps), and with `-N` you can visibly watch rows arrive one at a time.

- [ ] **Step 4: Chrome DevTools verification**

Open `http://127.0.0.1:8090/api/learn/demo/render-stream` directly in the browser. Take a screenshot using `mcp__chrome-devtools__take_screenshot` while the page is loading — the partial response should be visible (some rows rendered, more still streaming). Then take another screenshot after load completes. Inspect with `mcp__chrome-devtools__list_network_requests` and select the `render-stream` request; check the timing tab for a long "content download" phase (~1.25s) — that's the streaming.

- [ ] **Step 5: Commit**

```bash
git add template/components/_demo_clock.php route/learn.php
git commit -m "feat(learn): three render-method demo APIs for Lesson 4 (Components)"
```

### Task 4.4: Wire the render demos into Lesson 4 content (Components)

This task partially overlaps with M5.4 (Lesson 4 prose). Doing the interactive panel here keeps M5.4 focused on pure prose.

**Files:**
- Modify: `template/pages/learn/components.php`

- [ ] **Step 1: Add three "Try it" panels to the Components lesson body**

Add this block to the lesson body (the prose comes in M5.4; this just lands the three live panels):

```php
<h2>Three render methods, three demos</h2>
<p>Same template, three different APIs. The output is visually similar — the difference is in how the HTTP response is produced. Click each button to see the response in your browser, or run the curl commands shown beneath.</p>

<?php App::render('/components/_tryit', ['title' => 'App::render() — direct echo', 'body' => <<<HTML
  <p><code>App::render(\$tpl, \$args)</code> echoes the template's HTML. ZealPHP captures the output buffer and returns it as the response body.</p>
  <p><a class="lesson-chip" href="/api/learn/demo/render" target="_blank">Open /api/learn/demo/render →</a></p>
  <pre><code>curl http://localhost:8090/api/learn/demo/render</code></pre>
HTML]); ?>

<?php App::render('/components/_tryit', ['title' => 'App::renderToString() — composable HTML', 'body' => <<<HTML
  <p><code>App::renderToString(\$tpl, \$args)</code> returns the HTML as a string so you can wrap it, cache it, email it, or stream it inside an SSE event.</p>
  <p><a class="lesson-chip" href="/api/learn/demo/render-to-string" target="_blank">Open /api/learn/demo/render-to-string →</a></p>
  <pre><code>curl http://localhost:8090/api/learn/demo/render-to-string</code></pre>
HTML]); ?>

<?php App::render('/components/_tryit', ['title' => 'App::renderStream() — chunked SSR', 'body' => <<<HTML
  <p><code>App::renderStream(\$tpl, \$args)</code> returns a Generator. Each <code>yield</code> is flushed immediately — perfect for SSR shells, long lists, or AI token streams. The demo below sleeps 0.25s between rows so the streaming is visible.</p>
  <p><a class="lesson-chip" href="/api/learn/demo/render-stream" target="_blank">Open /api/learn/demo/render-stream →</a></p>
  <pre><code>curl -N http://localhost:8090/api/learn/demo/render-stream
# -N disables curl's output buffering so you can watch the rows arrive.</code></pre>
HTML]); ?>
```

- [ ] **Step 2: Visual verification**

```bash
php app.php start -p 8090 -d --pid-file /tmp/zealphp/learn_dev.pid
sleep 2
```

Navigate to `http://127.0.0.1:8090/learn/components` in Chrome DevTools, take a screenshot. Confirm all three "Try it" panels render with their headings and the open-link chips. Click each "Open …" link in a new tab and confirm the demos work end-to-end (third one visibly streams).

```bash
php app.php stop -p 8090 --pid-file /tmp/zealphp/learn_dev.pid
```

- [ ] **Step 3: Commit**

```bash
git add template/pages/learn/components.php
git commit -m "feat(learn): Lesson 4 — embed three render-method demos in Components page"
```

---

## Milestone 5 — Lesson content (1–7)

Each task writes the body of one lesson template. Use the existing `template/pages/getting-started.php` and `template/pages/templates.php` as tone references — confident, sentence-case headings, short paragraphs, plenty of `<pre>` code blocks, `_callout` and `_deepdive` components for asides.

### Task 5.1: Lesson 1 — Quick Start (`template/pages/learn.php`)

Replace the placeholder body with three sections:

1. **What ZealPHP is** — one short paragraph: "An async PHP framework on OpenSwoole. SSR streaming, WebSocket, coroutines, shared memory, sessions — one process, no sidecars."
2. **Why we made this section** — one paragraph: tutorial-app-first; the page you're reading is *also the demo*.
3. **The tour** — bulleted list linking to all 11 sub-lessons with one-sentence summaries each.

End with `<div class="lesson-chips"><a class="lesson-chip lesson-chip-next" href="/learn/create-app">Create a ZealPHP App →</a></div>`.

Commit: `feat(learn): lesson 1 quick start content`.

### Task 5.2: Lesson 2 — Create a ZealPHP App

Sections:
1. Install — TL;DR `curl ... install.sh | sudo bash`, then long form (link to `/getting-started`).
2. Scaffold — `composer create-project sibidharan/zealphp-project myapp`.
3. Run — `cd myapp && php app.php`.
4. Folder tour — labeled tree (use `<pre>` ascii).

Commit: `feat(learn): lesson 2 create-app content`.

### Task 5.3: Lesson 3 — Your First Page

Sections:
1. `public/index.php` — show 3-line echo HTML.
2. Upgrade to `App::render('_master', ...)`.
3. Implicit public routing rule with a code block.
4. `_tryit` panel: link out to a tiny demo route `/learn/demos/first-page` (no implementation needed — just text "(Coming in M11)" if not built).

Commit: `feat(learn): lesson 3 first-page content`.

### Task 5.4: Lesson 4 — Components

Tasks 4.3 + 4.4 already landed three "Try it" panels for the render methods. This task adds the surrounding prose.

Sections to add **above** the three render-method panels (which are already on the page from M4.4):
1. PHP templates as components — what they are, how `App::render` resolves `/components/_card` to `template/components/_card.php`.
2. Side-by-side: a React functional component vs. a PHP template — same outcome, simpler primitives.
3. The three render methods — short table with file links pointing at `src/App.php`. Then the "Three render methods, three demos" section already present.
4. `_deepdive` on parameter injection (reflection-cached at registration; zero overhead per request).

Commit: `feat(learn): lesson 4 components prose around render demos`.

### Task 5.5: Lesson 5 — Routing

Sections:
1. Implicit public (`public/X.php` → `/X`).
2. Implicit API (`api/users/get.php` → `GET /api/users`).
3. Explicit (`$app->route('/users/{id}', fn($id) => ...)`).
4. Namespaced (`nsRoute`, `nsPathRoute`).
5. Parameter injection rules — table.

Commit: `feat(learn): lesson 5 routing content`.

### Task 5.6: Lesson 6 — Sessions & Auth

Sections:
1. `session_start()` + `$_SESSION` — boilerplate.
2. **Live auth flow** — embed the actual register form pointing to `/api/learn/register` (same as Lesson 8) so visitors can register here too. Show the source code of `route/learn.php`'s register handler in a `<pre>` block beneath.
3. Coroutine-safe sessions — short callout.
4. `_tryit` panel: shows current `session_id()` and `$_SESSION` contents via a tiny route `/learn/demos/session-dump`.

Commit: `feat(learn): lesson 6 sessions+auth content`.

### Task 5.7: Lesson 7 — Add htmx

Sections:
1. The four attributes (`hx-get/post/target/swap`) — explained.
2. Progressive enhancement — form still posts if JS off.
3. `_tryit` panel: the counter button from Task 4.1.
4. `_deepdive`: htmx + SSE — explain why chat uses raw fetch instead.

Commit: `feat(learn): lesson 7 htmx content`.

### Task 5.8: Visual smoke pass

After all 7 lesson bodies are written:

```bash
php app.php start -p 8090 -d --pid-file /tmp/zealphp/learn_dev.pid
sleep 2
for slug in '' '/create-app' '/first-page' '/components' '/routing' '/sessions' '/htmx'; do
  echo "=== /learn$slug ==="
  curl -s "http://127.0.0.1:8090/learn$slug" | grep -E '<h1|<h2' | head -5
done
php app.php stop -p 8090 --pid-file /tmp/zealphp/learn_dev.pid
```

Confirm: each page has a real `<h1>` matching the lesson title and at least 2 `<h2>` section headings.

Open `/learn/htmx` in Chrome DevTools, click the counter button three times, screenshot — counter should increment without reloads.

No commit (verification only).

---

## Milestone 6 — Mock chat + frontend timeline + Lesson 9 layout

### Task 6.1: Frontend chat client (`public/js/learn.js`)

**Files:**
- Create: `public/js/learn.js`

- [ ] **Step 1: Write the file**

The chat client uses `Range.createContextualFragment` to parse HTML tokens streamed from the agent (instead of assigning to element properties), so all DOM construction is via safe APIs.

```javascript
// /js/learn.js — chat timeline client
(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', () => {
    const chatRoot = document.getElementById('learn-chat');
    if (chatRoot) initChat(chatRoot);
  });

  function htmlFragment(html) {
    return document.createRange().createContextualFragment(html);
  }

  function makeEl(tag, className, text) {
    const el = document.createElement(tag);
    if (className) el.className = className;
    if (text != null) el.textContent = text;
    return el;
  }

  function initChat(root) {
    const messages = root.querySelector('.chat-messages');
    const form     = root.querySelector('.chat-form');
    const input    = form.querySelector('input[name="message"]');
    const sendBtn  = form.querySelector('button');
    const modeBadge = root.querySelector('.chat-mode');

    let threadId = localStorage.getItem('zealphp_learn_thread') || cryptoRandomId();
    localStorage.setItem('zealphp_learn_thread', threadId);

    fetch('/api/learn/chat/status').then(r => r.json()).then(s => {
      if (modeBadge) {
        modeBadge.textContent = s.mock_mode ? 'Mock mode' : s.model;
        modeBadge.title = s.mock_mode ? 'Set OPENAI_API_KEY for real AI' : '';
      }
    }).catch(() => {});

    form.addEventListener('submit', (e) => {
      e.preventDefault();
      const text = input.value.trim();
      if (!text) return;
      appendUser(messages, text);
      input.value = '';
      sendBtn.disabled = true;
      streamChat(text, threadId, messages, () => { sendBtn.disabled = false; input.focus(); });
    });
  }

  function appendUser(messages, text) {
    const wrap = makeEl('div', 'chat-msg user');
    const bub  = makeEl('div', 'chat-bubble', text);
    wrap.appendChild(bub);
    messages.appendChild(wrap);
    messages.scrollTop = messages.scrollHeight;
  }

  function streamChat(message, threadId, messages, done) {
    const wrap = makeEl('div', 'chat-msg assistant');
    const bubble = makeEl('div', 'chat-bubble');
    wrap.appendChild(bubble);
    messages.appendChild(wrap);
    messages.scrollTop = messages.scrollHeight;

    let lastItem = null;
    const ensureText = () => {
      if (lastItem && lastItem.classList.contains('text')) return lastItem;
      lastItem = makeEl('div', 'chat-item text');
      bubble.appendChild(lastItem);
      return lastItem;
    };

    fetch('/api/learn/chat', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ message, thread_id: threadId }),
    }).then(resp => {
      if (resp.status === 401) {
        bubble.appendChild(makeEl('p', null, 'Please log in first.'));
        done();
        return;
      }
      const reader = resp.body.getReader();
      const decoder = new TextDecoder();
      let buffer = '';
      let currentEvent = null;

      function read() {
        reader.read().then(({ value, done: streamDone }) => {
          if (streamDone) { done(); return; }
          buffer += decoder.decode(value, { stream: true });
          const lines = buffer.split('\n');
          buffer = lines.pop();
          for (const line of lines) {
            if (line.startsWith('event: ')) {
              currentEvent = line.slice(7).trim();
            } else if (line.startsWith('data: ')) {
              try { handleEvent(currentEvent, JSON.parse(line.slice(6))); }
              catch (e) { /* ignore */ }
            }
          }
          messages.scrollTop = messages.scrollHeight;
          read();
        }).catch(() => done());
      }
      read();

      function handleEvent(ev, data) {
        if (ev === 'token') {
          const t = ensureText();
          t.appendChild(htmlFragment(data.token || ''));
        } else if (ev === 'tool_call') {
          const card = makeEl('div', 'chat-item tool');
          card.dataset.id = data.id;
          card.dataset.status = 'running';
          const head = makeEl('div', 'tool-head');
          head.appendChild(makeEl('span', 'tool-icon', '⚙'));
          head.appendChild(makeEl('span', 'tool-name', data.name || ''));
          head.appendChild(makeEl('span', 'tool-status', 'running'));
          card.appendChild(head);
          const det = makeEl('details', 'tool-detail');
          det.appendChild(makeEl('summary', null, 'args + result'));
          det.appendChild(makeEl('pre', 'tool-args'));
          const res = makeEl('pre', 'tool-result'); res.hidden = true;
          det.appendChild(res);
          card.appendChild(det);
          bubble.appendChild(card);
          lastItem = card;
        } else if (ev === 'tool_args') {
          const card = bubble.querySelector(`.chat-item.tool[data-id="${cssEscape(data.id)}"]`);
          if (card) card.querySelector('.tool-args').textContent += (data.delta || '');
        } else if (ev === 'tool_done') {
          const card = bubble.querySelector(`.chat-item.tool[data-id="${cssEscape(data.id)}"]`);
          if (card) {
            card.dataset.status = data.status || 'ok';
            card.querySelector('.tool-status').textContent = data.status === 'error' ? 'failed' : 'done';
            if (data.result_preview) {
              const r = card.querySelector('.tool-result');
              r.textContent = data.result_preview;
              r.hidden = false;
            }
          }
          lastItem = null;
        } else if (ev === 'notes_changed') {
          if (window.htmx) window.htmx.ajax('GET', '/api/learn/notes', { target: '#notes-list', swap: 'innerHTML' });
        } else if (ev === 'error') {
          const p = makeEl('p', null, 'Error: ' + (data.error || ''));
          p.style.color = '#b91c1c';
          bubble.appendChild(p);
        }
      }
    }).catch(err => {
      const p = makeEl('p', null, 'Network error: ' + String(err));
      p.style.color = '#b91c1c';
      bubble.appendChild(p);
      done();
    });
  }

  function cssEscape(s) { return String(s).replace(/"/g, '\\"'); }
  function cryptoRandomId() {
    const a = new Uint8Array(8);
    (window.crypto || window.msCrypto).getRandomValues(a);
    return Array.from(a, b => b.toString(16).padStart(2, '0')).join('');
  }
})();
```

Note: `htmlFragment` uses `Range.createContextualFragment` to parse the agent's HTML tokens — agent output is constrained by the system prompt to safe HTML (no `<script>`, no event handlers); the chat is auth-gated so only logged-in users can elicit responses about *their own* notes.

- [ ] **Step 2: Commit**

```bash
git add public/js/learn.js
git commit -m "feat(learn): chat timeline JS client (DOM-safe construction)"
```

### Task 6.2: Mock-mode chat endpoint + status

**Files:**
- Modify: `route/learn.php`

- [ ] **Step 1: Append chat endpoints**

Append after the existing routes in `route/learn.php`:

```php
$app->route('/api/learn/chat/status', ['methods' => ['GET']], function() {
    $key = (string)(getenv('OPENAI_API_KEY') ?: '');
    header('Content-Type: application/json');
    return [
        'ai_enabled' => $key !== '',
        'mock_mode'  => $key === '',
        'model'      => $key !== '' ? (getenv('ZEALPHP_LEARN_AI_MODEL') ?: 'gpt-4.1-mini') : 'mock-rules-v1',
    ];
});

\ZealPHP\Store::make('learn_chat_rl', 1024, [
    'ip'    => [\OpenSwoole\Table::TYPE_STRING, 45],
    'count' => [\OpenSwoole\Table::TYPE_INT, 4],
    'reset' => [\OpenSwoole\Table::TYPE_INT, 4],
]);

$app->route('/api/learn/chat', ['methods' => ['POST']], function($request, $response) {
    $u = learn_current_user();
    if (!$u) { http_response_code(401); header('Content-Type: application/json'); return ['error' => 'auth_required']; }
    $g = G::instance();
    $ip = $g->server['REMOTE_ADDR'] ?? 'unknown';
    $limit = (int)(getenv('ZEALPHP_LEARN_RATE_LIMIT') ?: 30);
    if (!learn_rate_limit('learn_chat_rl', $ip, $limit, 3600)) {
        $response->sse(function($emit) {
            $emit(json_encode(['error' => 'rate_limit']), 'error');
            $emit(json_encode(['done' => true]), 'done');
        });
        return;
    }
    $body = json_decode($g->zealphp_request->parent->getContent(), true) ?: [];
    $message  = trim((string)($body['message'] ?? ''));
    $threadId = (string)($body['thread_id'] ?? bin2hex(random_bytes(8)));
    if ($message === '' || strlen($message) > 2000) {
        $response->sse(function($emit) use ($threadId) {
            $emit(json_encode(['thread_id' => $threadId]), 'thread');
            $emit(json_encode(['error' => 'invalid_message']), 'error');
            $emit(json_encode(['done' => true]), 'done');
        });
        return;
    }
    $key = (string)(getenv('OPENAI_API_KEY') ?: '');
    if ($key === '') learn_chat_mock($response, $u, $message, $threadId);
    else learn_chat_real($response, $u, $message, $threadId, $key);
});
```

- [ ] **Step 2: Append `learn_chat_mock` helper**

```php
function learn_chat_mock($response, array $user, string $message, string $threadId): void {
    $db = learn_db_open();
    $userId = $user['user_id'];
    $msgLower = strtolower($message);

    $response->sse(function($emit) use ($db, $userId, $message, $msgLower, $threadId) {
        $emit(json_encode(['thread_id' => $threadId]), 'thread');

        if (preg_match('/(list|show all|what\'?s in)/i', $msgLower)) {
            $emit(json_encode(['id' => 'm1', 'name' => 'list_notes', 'phase' => 'start']), 'tool_call');
            usleep(120000);
            $notes = learn_notes_list($db, $userId);
            $emit(json_encode(['id' => 'm1', 'status' => 'ok', 'result_preview' => count($notes) . ' notes']), 'tool_done');
            if (empty($notes)) {
                $emit(json_encode(['token' => '<p>No notes yet. Try "create a note titled buy milk".</p>']), 'token');
            } else {
                $html = '<ul>' . implode('', array_map(fn($n) => '<li>' . htmlspecialchars($n['title']) . ' — id ' . (int)$n['id'] . '</li>', $notes)) . '</ul>';
                $emit(json_encode(['token' => '<p>Here are your notes:</p>' . $html]), 'token');
            }
        } elseif (preg_match('/(create|add)(\s+a)?\s+note(\s+(titled|called|saying))?\s+["\']?(.+?)["\']?$/i', $message, $m)) {
            $title = trim($m[5] ?? 'untitled');
            $emit(json_encode(['token' => '<p>Got it, creating that note.</p>']), 'token');
            $emit(json_encode(['id' => 'm2', 'name' => 'create_note', 'phase' => 'start']), 'tool_call');
            $json = json_encode(['title' => $title, 'body' => '']);
            foreach (str_split($json, 12) as $chunk) {
                $emit(json_encode(['id' => 'm2', 'delta' => $chunk]), 'tool_args');
                usleep(40000);
            }
            $newId = learn_notes_create($db, $userId, $title, '');
            $emit(json_encode(['id' => 'm2', 'status' => $newId ? 'ok' : 'error', 'result_preview' => $newId ? "id: $newId" : 'failed']), 'tool_done');
            $emit(json_encode([]), 'notes_changed');
            $emit(json_encode(['token' => "<p>Created note <strong>" . htmlspecialchars($title) . "</strong>.</p>"]), 'token');
        } elseif (preg_match('/delete\s+(?:note\s+)?["\']?(.+?)["\']?$/i', $message, $m)) {
            $needle = trim($m[1]);
            $notes = learn_notes_list($db, $userId);
            $hit = null;
            foreach ($notes as $n) if (stripos($n['title'], $needle) !== false) { $hit = $n; break; }
            if (!$hit) {
                $emit(json_encode(['token' => "<p>I couldn't find a note matching <em>" . htmlspecialchars($needle) . "</em>.</p>"]), 'token');
            } else {
                $emit(json_encode(['id' => 'm3', 'name' => 'delete_note', 'phase' => 'start']), 'tool_call');
                learn_notes_delete($db, $userId, (int)$hit['id']);
                $emit(json_encode(['id' => 'm3', 'status' => 'ok', 'result_preview' => 'deleted id ' . $hit['id']]), 'tool_done');
                $emit(json_encode([]), 'notes_changed');
                $emit(json_encode(['token' => "<p>Deleted note <strong>" . htmlspecialchars($hit['title']) . "</strong>.</p>"]), 'token');
            }
        } elseif (preg_match('/(search|find)\s+(.+)/i', $message, $m)) {
            $q = trim($m[2]);
            $emit(json_encode(['id' => 'm4', 'name' => 'search_notes', 'phase' => 'start']), 'tool_call');
            $hits = learn_notes_search($db, $userId, $q);
            $emit(json_encode(['id' => 'm4', 'status' => 'ok', 'result_preview' => count($hits) . ' hits']), 'tool_done');
            if (empty($hits)) $emit(json_encode(['token' => "<p>No notes match <em>" . htmlspecialchars($q) . "</em>.</p>"]), 'token');
            else $emit(json_encode(['token' => '<ul>' . implode('', array_map(fn($n) => '<li>' . htmlspecialchars($n['title']) . '</li>', $hits)) . '</ul>']), 'token');
        } else {
            $emit(json_encode(['token' => '<p>Mock mode is active — set <code>OPENAI_API_KEY</code> for the real model. Try: <em>create a note titled buy milk</em>, <em>list notes</em>, <em>delete buy milk</em>, <em>search groceries</em>.</p>']), 'token');
        }
        $emit(json_encode(['done' => true]), 'done');
    });
}

function learn_chat_real($response, array $user, string $message, string $threadId, string $apiKey): void {
    // Filled in by Milestone 7.
    $response->sse(function($emit) use ($threadId) {
        $emit(json_encode(['thread_id' => $threadId]), 'thread');
        $emit(json_encode(['token' => '<p>Real AI not wired yet (Milestone 7).</p>']), 'token');
        $emit(json_encode(['done' => true]), 'done');
    });
}
```

- [ ] **Step 3: Smoke-test mock chat with curl**

```bash
php app.php start -p 8090 -d --pid-file /tmp/zealphp/learn_dev.pid
sleep 2
curl -s -c /tmp/lc.txt -o /dev/null -H "Content-Type: application/json" \
  -d '{"username":"dora","password":"password123"}' \
  http://127.0.0.1:8090/api/learn/register
curl -s -b /tmp/lc.txt http://127.0.0.1:8090/api/learn/chat/status
curl -s -b /tmp/lc.txt -N -H "Content-Type: application/json" \
  -d '{"message":"create a note titled buy milk","thread_id":"t1"}' \
  http://127.0.0.1:8090/api/learn/chat | tee /tmp/sse.txt
grep -q 'event: tool_call' /tmp/sse.txt && echo "tool_call: OK"
grep -q 'event: tool_done' /tmp/sse.txt && echo "tool_done: OK"
grep -q 'event: notes_changed' /tmp/sse.txt && echo "notes_changed: OK"
curl -s -b /tmp/lc.txt http://127.0.0.1:8090/api/learn/notes | grep -q 'buy milk' && echo "persisted: OK"
php app.php stop -p 8090 --pid-file /tmp/zealphp/learn_dev.pid
rm -f /tmp/lc.txt /tmp/sse.txt storage/learn.db storage/learn.db-wal storage/learn.db-shm
```

Expected: all four `OK` lines.

- [ ] **Step 4: Commit**

```bash
git add route/learn.php
git commit -m "feat(learn): mock-mode chat endpoint with SSE tool-call timeline"
```

### Task 6.3: Lesson 9 page layout

**Files:**
- Modify: `template/pages/learn/ai-chat.php`

- [ ] **Step 1: Replace placeholder body**

```php
<?php use ZealPHP\App;
$user = function_exists('learn_current_user') ? learn_current_user() : null;
?>
<div class="learn-layout">
  <?php App::render('/_learn_sidebar', ['active' => 'learn/ai-chat']); ?>
  <article class="lesson-content">
    <?php App::render('/components/_lesson_header', [
      'number' => 9, 'title' => 'Add AI Chat',
      'subtitle' => 'A chat box that can read and modify your notes — streamed via SSE with live tool calls.',
      'prev' => ['slug' => 'learn/notes', 'title' => 'Build Personal Notes'],
      'next' => ['slug' => 'learn/async', 'title' => 'Async & Coroutines'],
    ]); ?>
    <?php App::render('/components/_youwilllearn', ['items' => [
      'Stream Server-Sent Events from a ZealPHP route with $response->sse()',
      'Surface tool calls in real time as the model uses them',
      'Pipe a Python OpenAI Agents SDK subprocess through PHP',
      'Auto-refresh the notes list when the agent mutates the vault',
    ]]); ?>
    <?php if (!$user): ?>
      <?php App::render('/components/_callout', [
        'variant' => 'warn', 'title' => 'Log in to use the chat',
        'body' => '<a href="/learn/notes">Register or log in</a> first.',
      ]); ?>
    <?php else: ?>
      <p>Logged in as <strong><?= htmlspecialchars($user['username']) ?></strong>.</p>
      <section class="chat">
        <div>
          <h3>Your notes</h3>
          <div id="notes-list" class="notes-list" hx-get="/api/learn/notes" hx-trigger="load" hx-swap="innerHTML">
            <p class="notes-empty">Loading…</p>
          </div>
        </div>
        <div id="learn-chat" class="chat-box">
          <div class="chat-head">Notes assistant <span class="chat-mode">…</span></div>
          <div class="chat-messages"></div>
          <form class="chat-form" autocomplete="off">
            <input type="text" name="message" placeholder="Ask anything about your notes…" required>
            <button type="submit">Send</button>
          </form>
        </div>
      </section>
      <h2>How this works</h2>
      <ol>
        <li>Your browser POSTs to <code>/api/learn/chat</code>.</li>
        <li>ZealPHP <code>proc_open</code>s the Python agent (<code>examples/agents/notes_agent.py</code>).</li>
        <li>Python streams Agents-SDK events to stdout. PHP re-emits them as SSE.</li>
        <li>Your browser appends tokens, renders tool cards, and refreshes the notes list on <code>notes_changed</code>.</li>
      </ol>
    <?php endif; ?>
  </article>
</div>
```

- [ ] **Step 2: Chrome DevTools verification**

Start server, register a user, navigate to `/learn/ai-chat`, send "create a note titled buy bread", screenshot to confirm tool-call card appears, notes list auto-refreshes.

- [ ] **Step 3: Commit**

```bash
git add template/pages/learn/ai-chat.php
git commit -m "feat(learn): Lesson 9 AI Chat page layout (functional in mock mode)"
```

---

## Milestone 6.5 — Chat history + ZealAPI file-based endpoints

Two framework primitives still missing from the demo: **persistent chat history** (so a conversation survives page reload) and **ZealAPI file-based routing** (handlers as `api/<path>/<verb>.php` files). Combining them gives one of the strongest teaching moments in the whole tutorial.

### Task 6.5.1: Add `chat_history` table + repo helpers (TDD)

**Files:**
- Modify: `route/learn.php` — add table in `learn_db_open`, add three helpers
- Modify: `tests/Unit/LearnNotesRepoTest.php` — add three new tests

- [ ] **Step 1: Add failing tests**

Append to `tests/Unit/LearnNotesRepoTest.php`:

```php
    public function test_chat_history_append_and_fetch(): void
    {
        $items = [['type' => 'text', 'html' => '<p>hi</p>']];
        $id = \learn_chat_history_append($this->db, $this->aliceId, 't1', 'user', $items);
        $this->assertIsInt($id);
        $rows = \learn_chat_history_for_thread($this->db, $this->aliceId, 't1');
        $this->assertCount(1, $rows);
        $this->assertSame('user', $rows[0]['role']);
        $this->assertSame($items, json_decode($rows[0]['items_json'], true));
    }

    public function test_chat_history_user_isolation(): void
    {
        \learn_chat_history_append($this->db, $this->aliceId, 't1', 'user', [['type' => 'text', 'html' => 'alice']]);
        \learn_chat_history_append($this->db, $this->bobId,   't1', 'user', [['type' => 'text', 'html' => 'bob']]);
        $aliceRows = \learn_chat_history_for_thread($this->db, $this->aliceId, 't1');
        $this->assertCount(1, $aliceRows);
        $this->assertStringContainsString('alice', $aliceRows[0]['items_json']);
    }

    public function test_chat_history_thread_list(): void
    {
        \learn_chat_history_append($this->db, $this->aliceId, 't1', 'user', [['type' => 'text', 'html' => 'a']]);
        \learn_chat_history_append($this->db, $this->aliceId, 't2', 'user', [['type' => 'text', 'html' => 'b']]);
        $threads = \learn_chat_history_threads($this->db, $this->aliceId);
        $this->assertCount(2, $threads);
        $this->assertContains('t1', array_column($threads, 'thread_id'));
    }
```

- [ ] **Step 2: Run tests, confirm fail**

```bash
./vendor/bin/phpunit tests/Unit/LearnNotesRepoTest.php
```

Expected: three new tests fail with `undefined function learn_chat_history_*`.

- [ ] **Step 3: Add the schema row to `learn_db_open`**

Inside `learn_db_open`, append after the existing `notes` table creation:

```php
$pdo->query("CREATE TABLE IF NOT EXISTS chat_history (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, thread_id TEXT NOT NULL, role TEXT NOT NULL, items_json TEXT NOT NULL, created_at INTEGER NOT NULL)");
$pdo->query("CREATE INDEX IF NOT EXISTS idx_chat_user_thread_time ON chat_history(user_id, thread_id, created_at)");
```

- [ ] **Step 4: Add the three repo helpers**

Add after `learn_notes_search`:

```php
if (!function_exists('learn_chat_history_append')) {
    function learn_chat_history_append(\PDO $db, int $userId, string $threadId, string $role, array $items): int {
        $stmt = $db->prepare('INSERT INTO chat_history (user_id, thread_id, role, items_json, created_at) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$userId, $threadId, $role, json_encode($items, JSON_UNESCAPED_UNICODE), time()]);
        return (int)$db->lastInsertId();
    }
}

if (!function_exists('learn_chat_history_for_thread')) {
    function learn_chat_history_for_thread(\PDO $db, int $userId, string $threadId): array {
        $stmt = $db->prepare('SELECT id, role, items_json, created_at FROM chat_history WHERE user_id = ? AND thread_id = ? ORDER BY created_at ASC, id ASC');
        $stmt->execute([$userId, $threadId]);
        return $stmt->fetchAll();
    }
}

if (!function_exists('learn_chat_history_threads')) {
    function learn_chat_history_threads(\PDO $db, int $userId, int $limit = 10): array {
        $stmt = $db->prepare('SELECT thread_id, MAX(created_at) AS last_at, COUNT(*) AS turns FROM chat_history WHERE user_id = ? GROUP BY thread_id ORDER BY last_at DESC LIMIT ?');
        $stmt->execute([$userId, $limit]);
        return $stmt->fetchAll();
    }
}
```

- [ ] **Step 5: Run tests, confirm pass**

```bash
./vendor/bin/phpunit tests/Unit/LearnNotesRepoTest.php --testdox
```

Expected: all 10 tests pass (7 original + 3 new).

- [ ] **Step 6: Commit**

```bash
git add tests/Unit/LearnNotesRepoTest.php route/learn.php
git commit -m "feat(learn): chat_history table + repo helpers (TDD: 3 new tests pass)"
```

### Task 6.5.2: Persist user + assistant turns from chat handlers

The mock chat handler builds the SSE timeline in PHP; the real chat handler proxies Python's events. Both need to capture turn data and persist on `done`.

**Files:**
- Modify: `route/learn.php` — wrap `$emit` callable in mock + real chat helpers

- [ ] **Step 1: Replace `learn_chat_mock` with a version that accumulates items**

Use the same SSE shape as before, but route every `$emit` through an `accumulator` that maintains an `items` array. After the final `done` event, call `learn_chat_history_append` twice (user role then assistant role).

The diff is conceptually: wrap each `$emit(json, 'event')` call to also push into `$items` based on the event type. Detailed implementation:

```php
function learn_chat_mock($response, array $user, string $message, string $threadId): void {
    $db = learn_db_open();
    $userId = $user['user_id'];

    // Persist the user turn immediately so a refresh shows it even if the assistant fails.
    learn_chat_history_append($db, $userId, $threadId, 'user', [['type' => 'text', 'html' => '<p>' . htmlspecialchars($message) . '</p>']]);

    $msgLower = strtolower($message);

    $response->sse(function($emit) use ($db, $userId, $message, $msgLower, $threadId) {
        // Accumulator-emit wrapper: forwards to the real $emit and tracks items.
        $items = [];
        $textBuf = '';
        $flushText = function() use (&$items, &$textBuf) {
            if ($textBuf !== '') { $items[] = ['type' => 'text', 'html' => $textBuf]; $textBuf = ''; }
        };
        $sse = function(string $data, string $event) use ($emit, &$items, &$textBuf, $flushText) {
            $emit($data, $event);
            $payload = json_decode($data, true) ?: [];
            if ($event === 'token') {
                $textBuf .= (string)($payload['token'] ?? '');
            } elseif ($event === 'tool_call') {
                $flushText();
                $items[] = ['type' => 'tool', 'id' => $payload['id'] ?? '?', 'name' => $payload['name'] ?? '?', 'status' => 'running', 'args' => '', 'result' => ''];
            } elseif ($event === 'tool_args') {
                foreach ($items as &$it) if ($it['type'] === 'tool' && $it['id'] === ($payload['id'] ?? '')) { $it['args'] .= (string)($payload['delta'] ?? ''); break; }
            } elseif ($event === 'tool_done') {
                foreach ($items as &$it) if ($it['type'] === 'tool' && $it['id'] === ($payload['id'] ?? '')) { $it['status'] = $payload['status'] ?? 'ok'; $it['result'] = (string)($payload['result_preview'] ?? ''); break; }
            }
        };

        $sse(json_encode(['thread_id' => $threadId]), 'thread');

        if (preg_match('/(list|show all|what\'?s in)/i', $msgLower)) {
            $sse(json_encode(['id' => 'm1', 'name' => 'list_notes', 'phase' => 'start']), 'tool_call');
            usleep(120000);
            $notes = learn_notes_list($db, $userId);
            $sse(json_encode(['id' => 'm1', 'status' => 'ok', 'result_preview' => count($notes) . ' notes']), 'tool_done');
            if (empty($notes)) {
                $sse(json_encode(['token' => '<p>No notes yet. Try "create a note titled buy milk".</p>']), 'token');
            } else {
                $html = '<ul>' . implode('', array_map(fn($n) => '<li>' . htmlspecialchars($n['title']) . ' — id ' . (int)$n['id'] . '</li>', $notes)) . '</ul>';
                $sse(json_encode(['token' => '<p>Here are your notes:</p>' . $html]), 'token');
            }
        } elseif (preg_match('/(create|add)(\s+a)?\s+note(\s+(titled|called|saying))?\s+["\']?(.+?)["\']?$/i', $message, $m)) {
            $title = trim($m[5] ?? 'untitled');
            $sse(json_encode(['token' => '<p>Got it, creating that note.</p>']), 'token');
            $sse(json_encode(['id' => 'm2', 'name' => 'create_note', 'phase' => 'start']), 'tool_call');
            $json = json_encode(['title' => $title, 'body' => '']);
            foreach (str_split($json, 12) as $chunk) {
                $sse(json_encode(['id' => 'm2', 'delta' => $chunk]), 'tool_args');
                usleep(40000);
            }
            $newId = learn_notes_create($db, $userId, $title, '');
            $sse(json_encode(['id' => 'm2', 'status' => $newId ? 'ok' : 'error', 'result_preview' => $newId ? "id: $newId" : 'failed']), 'tool_done');
            $sse(json_encode([]), 'notes_changed');
            $sse(json_encode(['token' => "<p>Created note <strong>" . htmlspecialchars($title) . "</strong>.</p>"]), 'token');
        } elseif (preg_match('/delete\s+(?:note\s+)?["\']?(.+?)["\']?$/i', $message, $m)) {
            $needle = trim($m[1]);
            $notes = learn_notes_list($db, $userId);
            $hit = null;
            foreach ($notes as $n) if (stripos($n['title'], $needle) !== false) { $hit = $n; break; }
            if (!$hit) {
                $sse(json_encode(['token' => "<p>I couldn't find a note matching <em>" . htmlspecialchars($needle) . "</em>.</p>"]), 'token');
            } else {
                $sse(json_encode(['id' => 'm3', 'name' => 'delete_note', 'phase' => 'start']), 'tool_call');
                learn_notes_delete($db, $userId, (int)$hit['id']);
                $sse(json_encode(['id' => 'm3', 'status' => 'ok', 'result_preview' => 'deleted id ' . $hit['id']]), 'tool_done');
                $sse(json_encode([]), 'notes_changed');
                $sse(json_encode(['token' => "<p>Deleted note <strong>" . htmlspecialchars($hit['title']) . "</strong>.</p>"]), 'token');
            }
        } elseif (preg_match('/(search|find)\s+(.+)/i', $message, $m)) {
            $q = trim($m[2]);
            $sse(json_encode(['id' => 'm4', 'name' => 'search_notes', 'phase' => 'start']), 'tool_call');
            $hits = learn_notes_search($db, $userId, $q);
            $sse(json_encode(['id' => 'm4', 'status' => 'ok', 'result_preview' => count($hits) . ' hits']), 'tool_done');
            if (empty($hits)) $sse(json_encode(['token' => "<p>No notes match <em>" . htmlspecialchars($q) . "</em>.</p>"]), 'token');
            else $sse(json_encode(['token' => '<ul>' . implode('', array_map(fn($n) => '<li>' . htmlspecialchars($n['title']) . '</li>', $hits)) . '</ul>']), 'token');
        } else {
            $sse(json_encode(['token' => '<p>Mock mode is active — set <code>OPENAI_API_KEY</code> for the real model.</p>']), 'token');
        }

        $flushText();
        learn_chat_history_append($db, $userId, $threadId, 'assistant', $items);
        $emit(json_encode(['done' => true]), 'done');
    });
}
```

- [ ] **Step 2: Mirror the same accumulator pattern in `learn_chat_real` (M7.2)**

Note: this task overlaps with M7. When you reach Task 7.2, the SSE proxy loop also accumulates items via the same `$sse` wrapper before re-emitting Python's events, then calls `learn_chat_history_append` on `done`. The structure is the same wrapper; the body of the helper reads from `proc_open` output instead of generating events directly.

- [ ] **Step 3: Smoke test that history is being written**

```bash
php app.php start -p 8090 -d --pid-file /tmp/zealphp/learn_dev.pid
sleep 2
curl -s -c /tmp/lc.txt -o /dev/null -H "Content-Type: application/json" \
  -d '{"username":"hist","password":"password123"}' \
  http://127.0.0.1:8090/api/learn/register
curl -s -b /tmp/lc.txt -N -H "Content-Type: application/json" \
  -d '{"message":"list my notes","thread_id":"history-test"}' \
  http://127.0.0.1:8090/api/learn/chat > /dev/null
sqlite3 storage/learn.db "SELECT role, substr(items_json, 1, 60) FROM chat_history WHERE thread_id = 'history-test' ORDER BY id"
php app.php stop -p 8090 --pid-file /tmp/zealphp/learn_dev.pid
rm -f /tmp/lc.txt storage/learn.db storage/learn.db-wal storage/learn.db-shm
```

Expected: two rows — one `user`, one `assistant`, with items_json containing text + tool entries.

- [ ] **Step 4: Commit**

```bash
git add route/learn.php
git commit -m "feat(learn): persist chat turns to SQLite for both user and assistant"
```

### Task 6.5.3: Move `/api/learn/chat/status` to a ZealAPI file

**Files:**
- Create: `api/learn/chat/status.php`
- Modify: `route/learn.php` — remove the explicit-route registration

- [ ] **Step 1: Write the ZealAPI file**

Write `/var/labsstorage/home/sibidharan/zealphp/api/learn/chat/status.php`:

```php
<?php
// ZealAPI file: GET /api/learn/chat/status maps to api/learn/chat/status.php.
// The variable name MUST match basename($file, '.php') — here: $status.
// $this is the ZealAPI instance; we can call $this->response(), $this->json(), etc.
${basename(__FILE__, '.php')} = function () {
    $key = (string)(getenv('OPENAI_API_KEY') ?: '');
    $this->response($this->json([
        'ai_enabled' => $key !== '',
        'mock_mode'  => $key === '',
        'model'      => $key !== '' ? (getenv('ZEALPHP_LEARN_AI_MODEL') ?: 'gpt-4.1-mini') : 'mock-rules-v1',
    ]), 200);
};
```

- [ ] **Step 2: Remove the explicit route in `route/learn.php`**

Find and delete this block:

```php
$app->route('/api/learn/chat/status', ['methods' => ['GET']], function() {
    $key = (string)(getenv('OPENAI_API_KEY') ?: '');
    header('Content-Type: application/json');
    return [
        'ai_enabled' => $key !== '',
        'mock_mode'  => $key === '',
        'model'      => $key !== '' ? (getenv('ZEALPHP_LEARN_AI_MODEL') ?: 'gpt-4.1-mini') : 'mock-rules-v1',
    ];
});
```

- [ ] **Step 3: Verify the endpoint still works (now resolved via ZealAPI)**

```bash
php app.php start -p 8090 -d --pid-file /tmp/zealphp/learn_dev.pid
sleep 2
curl -s http://127.0.0.1:8090/api/learn/chat/status | python3 -m json.tool
php app.php stop -p 8090 --pid-file /tmp/zealphp/learn_dev.pid
```

Expected: same JSON shape as before — `ai_enabled`, `mock_mode`, `model`.

- [ ] **Step 4: Commit**

```bash
git add api/learn/chat/status.php route/learn.php
git commit -m "feat(learn): chat/status as ZealAPI file (file-based routing demo)"
```

### Task 6.5.4: ZealAPI history endpoint with `App::renderStream`

**Files:**
- Create: `api/learn/chat/history.php`
- Create: `template/components/_chat_history_bubble.php`

- [ ] **Step 1: Write the history bubble component**

Write `/var/labsstorage/home/sibidharan/zealphp/template/components/_chat_history_bubble.php`:

```php
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
```

- [ ] **Step 2: Write the ZealAPI history file**

Write `/var/labsstorage/home/sibidharan/zealphp/api/learn/chat/history.php`:

```php
<?php
// ZealAPI file: GET /api/learn/chat/history?thread_id=XYZ
// Streams each historical message as an HTML fragment via App::renderStream.

use ZealPHP\App;
use ZealPHP\G;

require_once App::$cwd . '/route/learn.php';

${basename(__FILE__, '.php')} = function () {
    $u = learn_current_user();
    if (!$u) {
        $this->response($this->json(['error' => 'auth_required']), 401);
        return;
    }
    $g = G::instance();
    $threadId = (string)($g->get['thread_id'] ?? '');
    if ($threadId === '') {
        $this->response($this->json(['error' => 'thread_id required']), 422);
        return;
    }

    $db = learn_db_open();
    $rows = learn_chat_history_for_thread($db, $u['user_id'], $threadId);

    header('Content-Type: text/html; charset=utf-8');
    return (function() use ($rows) {
        if (empty($rows)) {
            yield '<p class="chat-empty">No history yet — start a new conversation.</p>';
            return;
        }
        foreach ($rows as $row) {
            yield from App::renderStream('/components/_chat_history_bubble', [
                'role'  => $row['role'],
                'items' => json_decode($row['items_json'], true) ?: [],
            ]);
        }
    })();
};
```

- [ ] **Step 3: Smoke-test the ZealAPI history endpoint**

```bash
php app.php start -p 8090 -d --pid-file /tmp/zealphp/learn_dev.pid
sleep 2
# Register + chat to populate history
curl -s -c /tmp/lc.txt -o /dev/null -H "Content-Type: application/json" \
  -d '{"username":"hist2","password":"password123"}' \
  http://127.0.0.1:8090/api/learn/register
curl -s -b /tmp/lc.txt -N -H "Content-Type: application/json" \
  -d '{"message":"create a note titled buy milk","thread_id":"hh"}' \
  http://127.0.0.1:8090/api/learn/chat > /dev/null

# Unauth → 401
curl -s -o /dev/null -w "unauth: %{http_code}\n" \
  'http://127.0.0.1:8090/api/learn/chat/history?thread_id=hh'

# Authed → HTML stream
curl -s -b /tmp/lc.txt 'http://127.0.0.1:8090/api/learn/chat/history?thread_id=hh' | head -20

php app.php stop -p 8090 --pid-file /tmp/zealphp/learn_dev.pid
rm -f /tmp/lc.txt storage/learn.db*
```

Expected: `unauth: 401`, then HTML containing `class="chat-msg user"` and `class="chat-msg assistant"` with the tool card from the create-note turn.

- [ ] **Step 4: Commit**

```bash
git add api/learn/chat/history.php template/components/_chat_history_bubble.php
git commit -m "feat(learn): /api/learn/chat/history ZealAPI file with renderStream"
```

### Task 6.5.5: Wire chat history loader into Lesson 9

**Files:**
- Modify: `template/pages/learn/ai-chat.php`
- Modify: `public/js/learn.js`

- [ ] **Step 1: Add a history-loader div above the messages container**

In `template/pages/learn/ai-chat.php`, replace the `<div id="learn-chat" class="chat-box">` block with:

```php
<div id="learn-chat" class="chat-box" data-thread-id="">
  <div class="chat-head">
    Notes assistant
    <span class="chat-mode">…</span>
    <button type="button" class="chat-new" title="Start a fresh conversation">New thread</button>
  </div>
  <div class="chat-history" hidden></div>
  <div class="chat-messages"></div>
  <form class="chat-form" autocomplete="off">
    <input type="text" name="message" placeholder="Ask anything about your notes…" required>
    <button type="submit">Send</button>
  </form>
</div>
```

- [ ] **Step 2: Add history loader logic to `learn.js`**

Insert at the top of `initChat`, just after the `chatRoot` variables are declared:

```javascript
    const history  = root.querySelector('.chat-history');
    const newBtn   = root.querySelector('.chat-new');
    let threadId = localStorage.getItem('zealphp_learn_thread') || cryptoRandomId();
    localStorage.setItem('zealphp_learn_thread', threadId);
    root.dataset.threadId = threadId;

    // Load history for this thread (renders into .chat-history via ZealAPI's renderStream).
    function loadHistory() {
      history.textContent = '';
      history.hidden = false;
      fetch('/api/learn/chat/history?thread_id=' + encodeURIComponent(threadId))
        .then(r => r.ok ? r.text() : '')
        .then(html => {
          if (!html) { history.hidden = true; return; }
          history.appendChild(htmlFragment(html));
        });
    }
    loadHistory();

    if (newBtn) newBtn.addEventListener('click', () => {
      threadId = cryptoRandomId();
      localStorage.setItem('zealphp_learn_thread', threadId);
      root.dataset.threadId = threadId;
      history.textContent = '';
      messages.textContent = '';
      loadHistory();
    });
```

Also remove the duplicate `threadId` line further down (the original one that's now superseded by the block above).

- [ ] **Step 3: Chrome DevTools verification**

```bash
php app.php start -p 8090 -d --pid-file /tmp/zealphp/learn_dev.pid
sleep 2
```

1. Register `irene`/`password123` from `/learn/notes`.
2. Navigate to `/learn/ai-chat`. Send 2-3 chat messages. Screenshot.
3. **Reload the page (F5).** Screenshot — history should re-render with the same bubbles + tool cards.
4. Click "New thread". Verify history clears and a new thread_id appears in localStorage.
5. `list_console_messages` — zero errors.

```bash
php app.php stop -p 8090 --pid-file /tmp/zealphp/learn_dev.pid
```

- [ ] **Step 4: Commit**

```bash
git add template/pages/learn/ai-chat.php public/js/learn.js
git commit -m "feat(learn): Lesson 9 loads chat history on mount via ZealAPI + renderStream"
```

### Task 6.5.6: Lesson 5 "Try it" panel pointing at the ZealAPI file

**Files:**
- Modify: `template/pages/learn/routing.php`

When writing Lesson 5's body in M5.5, add a "Try it now" panel that explicitly demonstrates the file-based pattern by linking to `/api/learn/chat/status` (no path params needed) and showing the file contents inline.

```php
<?php App::render('/components/_tryit', ['title' => 'ZealAPI in action', 'body' => <<<HTML
  <p>A real ZealAPI handler shipping in this codebase. URL <code>GET /api/learn/chat/status</code> maps to the file <code>api/learn/chat/status.php</code>:</p>
  <pre><code>// api/learn/chat/status.php
\${basename(__FILE__, '.php')} = function () {
    \$key = (string)(getenv('OPENAI_API_KEY') ?: '');
    \$this->response(\$this->json([
        'ai_enabled' => \$key !== '',
        'mock_mode'  => \$key === '',
    ]), 200);
};</code></pre>
  <p><a class="lesson-chip" href="/api/learn/chat/status" target="_blank">Call /api/learn/chat/status →</a></p>
  <p>The variable name (<code>\$status</code>) must match the file's basename. Inside the closure, <code>\$this</code> is the ZealAPI instance.</p>
HTML]); ?>
```

Commit (handled in M5.5): `feat(learn): lesson 5 routing prose + ZealAPI Try it panel`.

### Task 6.5.7: WebSocket cross-tab notes sync

Demonstrates `App::ws()` + cross-worker fanout. Open `/learn/notes` in two browser tabs (same user). Add a note in tab A → tab B sees it appear within ~100ms, no polling.

**Files:**
- Modify: `route/learn.php` — add WebSocket handler + broadcast helper, hook into notes CRUD
- Modify: `public/js/learn.js` — open WebSocket on Notes/AI Chat pages, refresh on message

- [ ] **Step 1: Add a Store table mapping `fd → user_id` and the WebSocket handler to `route/learn.php`**

Append after the rate-limit stores:

```php
// fd -> user_id mapping for the /ws/learn WebSocket. Worker-shared via Store.
\ZealPHP\Store::make('learn_ws_clients', 4096, [
    'user_id' => [\OpenSwoole\Table::TYPE_INT, 8],
]);

$app->ws('/ws/learn',
    onMessage: function($server, $frame) {
        // Echo client pings as keepalive; no other client→server messages used.
        if (($frame->data ?? '') === 'ping') $server->push($frame->fd, 'pong');
    },
    onOpen: function($server, $request) {
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        $userId = (int)($_SESSION['user_id'] ?? 0);
        if (!$userId) { $server->disconnect($request->fd, 1008, 'auth_required'); return; }
        \ZealPHP\Store::set('learn_ws_clients', (string)$request->fd, ['user_id' => $userId]);
    },
    onClose: function($server, $fd) {
        \ZealPHP\Store::del('learn_ws_clients', (string)$fd);
    },
);

/**
 * Push a JSON message to every WebSocket client whose session belongs to $userId.
 */
function learn_ws_broadcast(int $userId, array $payload): void {
    $server = \ZealPHP\App::getServer();
    if (!$server) return;
    $json = json_encode($payload);
    foreach (\ZealPHP\Store::table('learn_ws_clients') as $fd => $row) {
        if ((int)($row['user_id'] ?? 0) === $userId) {
            try { @$server->push((int)$fd, $json); } catch (\Throwable $e) { /* fd closed; cleanup happens via onClose */ }
        }
    }
}
```

- [ ] **Step 2: Wire broadcasts into the three notes-mutating endpoints**

In the POST `/api/learn/notes` handler, after `learn_notes_create` succeeds and before returning the rendered card:

```php
    learn_ws_broadcast($u['user_id'], ['type' => 'note_changed', 'op' => 'create', 'id' => $id]);
```

In the POST `/api/learn/notes/{id}` (update) handler, after `learn_notes_update` succeeds:

```php
    learn_ws_broadcast($u['user_id'], ['type' => 'note_changed', 'op' => 'update', 'id' => (int)$id]);
```

In the DELETE handler, after `learn_notes_delete` succeeds:

```php
    learn_ws_broadcast($u['user_id'], ['type' => 'note_changed', 'op' => 'delete', 'id' => (int)$id]);
```

Also broadcast from inside the chat handlers whenever `notes_changed` is emitted (since the agent mutating notes is conceptually the same event). Find each `$sse(json_encode([]), 'notes_changed');` (and the equivalent in real mode) and add immediately after:

```php
    learn_ws_broadcast($userId, ['type' => 'note_changed', 'op' => 'chat']);
```

- [ ] **Step 3: Add the WebSocket client to `learn.js`**

Append after the chat init code:

```javascript
  // Cross-tab notes sync via WebSocket. Opens on /learn/notes and /learn/ai-chat.
  document.addEventListener('DOMContentLoaded', () => {
    const notesList = document.getElementById('notes-list');
    if (!notesList) return;
    const proto = location.protocol === 'https:' ? 'wss:' : 'ws:';
    let ws = null;
    let reconnectDelay = 500;
    function connect() {
      try { ws = new WebSocket(proto + '//' + location.host + '/ws/learn'); }
      catch (e) { return; }
      ws.addEventListener('open', () => { reconnectDelay = 500; });
      ws.addEventListener('message', (ev) => {
        try {
          const msg = JSON.parse(ev.data);
          if (msg.type === 'note_changed' && window.htmx) {
            window.htmx.ajax('GET', '/api/learn/notes', { target: '#notes-list', swap: 'innerHTML' });
          }
        } catch (e) { /* ignore */ }
      });
      ws.addEventListener('close', () => {
        // Cap exponential backoff at 10s.
        reconnectDelay = Math.min(reconnectDelay * 2, 10000);
        setTimeout(connect, reconnectDelay);
      });
      // Keepalive every 25s.
      setInterval(() => { if (ws && ws.readyState === 1) ws.send('ping'); }, 25000);
    }
    connect();
  });
```

- [ ] **Step 4: Smoke test with two `wscat` clients (or two Chrome tabs)**

```bash
php app.php start -p 8090 -d --pid-file /tmp/zealphp/learn_dev.pid
sleep 2
# Register a user and stash the cookie.
curl -s -c /tmp/lc.txt -o /dev/null -H "Content-Type: application/json" \
  -d '{"username":"wsuser","password":"password123"}' \
  http://127.0.0.1:8090/api/learn/register

# Quick WebSocket test using a tiny PHP CLI client (no wscat dep).
php -r '
$cookies = file_get_contents("/tmp/lc.txt");
preg_match("/PHPSESSID\s+(\S+)/", $cookies, $m);
$sid = $m[1] ?? "";
$h = "GET /ws/learn HTTP/1.1\r\nHost: 127.0.0.1:8090\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\nSec-WebSocket-Version: 13\r\nCookie: PHPSESSID=$sid\r\n\r\n";
$s = stream_socket_client("tcp://127.0.0.1:8090", $errno, $err, 2);
fwrite($s, $h);
$resp = fread($s, 1024);
echo strpos($resp, "101 Switching") !== false ? "ws-upgrade: OK\n" : "ws-upgrade: FAIL\n";
stream_set_timeout($s, 3);
sleep(1);
echo "(awaiting broadcast)\n";
$msg = fread($s, 4096);
echo "received: " . bin2hex(substr($msg, 0, 8)) . "...\n";
fclose($s);
' &
WSPID=$!

# After a beat, fire a note create — should arrive on the WebSocket.
sleep 1
curl -s -b /tmp/lc.txt -o /dev/null -H "Content-Type: application/json" \
  -d '{"title":"ws-test","body":""}' http://127.0.0.1:8090/api/learn/notes

wait $WSPID
php app.php stop -p 8090 --pid-file /tmp/zealphp/learn_dev.pid
rm -f /tmp/lc.txt storage/learn.db*
```

Expected: `ws-upgrade: OK`, then `received: ...` with non-empty hex (the broadcast frame).

- [ ] **Step 5: Chrome DevTools verification — two-tab demo**

```bash
php app.php start -p 8090 -d --pid-file /tmp/zealphp/learn_dev.pid
sleep 2
```

1. `mcp__chrome-devtools__new_page` → `/learn/notes`, register `wsdemo`/`password123`.
2. `mcp__chrome-devtools__new_page` again → `/learn/notes` (second tab, same browser → shares session).
3. Use the `mcp__chrome-devtools__list_pages` and `select_page` tools to flip between them.
4. In tab A: fill the note form, submit.
5. In tab B: take a screenshot **without** any interaction — the new note should already be visible (WebSocket pushed the change, htmx refreshed via the JS listener).
6. `list_console_messages` on tab B — no errors.

```bash
php app.php stop -p 8090 --pid-file /tmp/zealphp/learn_dev.pid
```

- [ ] **Step 6: Lesson 7 deep-dive callout (handled inline)**

When writing Lesson 7's body in M5.7, add:

```php
<?php App::render('/components/_deepdive', [
  'title' => 'When htmx isn\'t enough — WebSocket',
  'body'  => '<p>htmx is great for request/response interactions, but server-pushed events (live updates, multi-tab sync) need a long-lived connection. ZealPHP\'s <code>App::ws()</code> handler is the same shape as a route handler — it just receives <code>$server</code> + <code>$frame</code> instead of <code>$request</code> + <code>$response</code>.</p><p>This very tutorial uses it: open <a href="/learn/notes">Lesson 8</a> in two tabs, add a note in one — the other tab updates without polling. The handler is <code>$app->ws(\'/ws/learn\', ...)</code> in <code>route/learn.php</code>; the client lives in <code>public/js/learn.js</code>.</p>',
]); ?>
```

- [ ] **Step 7: Commit**

```bash
git add route/learn.php public/js/learn.js
git commit -m "feat(learn): WebSocket cross-tab notes sync (App::ws + cross-worker fanout)"
```

---

## Milestone 7 — Python agent + real-mode SSE proxy

### Task 7.1: Python notes agent

**Files:**
- Create: `examples/agents/notes_agent.py`

- [ ] **Step 1: Write the agent**

```python
#!/usr/bin/env -S uv run --with openai-agents
# /// script
# requires-python = ">=3.10"
# dependencies = ["openai-agents"]
# ///
"""ZealPHP Learn notes agent — 6 tools, scoped to USER_ID, streams SSE events."""
import asyncio, base64, json, os, sqlite3, sys, time
from agents import Agent, Runner, SQLiteSession, function_tool

DB_PATH = None
USER_ID = None
MAX_NOTES = int(os.environ.get("ZEALPHP_LEARN_MAX_NOTES", "256"))

def _db():
    c = sqlite3.connect(DB_PATH, timeout=2.0)
    c.row_factory = sqlite3.Row
    c.execute("PRAGMA journal_mode = WAL")
    c.execute("PRAGMA foreign_keys = ON")
    return c

@function_tool
def list_notes() -> str:
    with _db() as c:
        rows = c.execute("SELECT id, title, updated_at FROM notes WHERE user_id=? ORDER BY updated_at DESC", (USER_ID,)).fetchall()
    if not rows: return "(no notes)"
    return "\n".join(f"id={r['id']} title={r['title']!r}" for r in rows)

@function_tool
def read_note(note_id: int) -> str:
    with _db() as c:
        r = c.execute("SELECT id, title, body FROM notes WHERE id=? AND user_id=?", (note_id, USER_ID)).fetchone()
    return f"id={r['id']} title={r['title']!r}\\n\\n{r['body']}" if r else "Note not found."

@function_tool
def search_notes(query: str) -> str:
    q = f"%{query}%"
    with _db() as c:
        rows = c.execute("SELECT id, title, substr(body, 1, 80) AS snip FROM notes WHERE user_id=? AND (title LIKE ? OR body LIKE ?) ORDER BY updated_at DESC LIMIT 10", (USER_ID, q, q)).fetchall()
    if not rows: return f"(no matches for {query!r})"
    return "\n".join(f"id={r['id']} title={r['title']!r}" for r in rows)

@function_tool
def create_note(title: str, body: str) -> str:
    title = title.strip()
    if not title or len(title) > 200: return "Error: title 1-200 chars."
    if len(body) > 4096: return "Error: body too long."
    now = int(time.time())
    with _db() as c:
        count = c.execute("SELECT COUNT(*) FROM notes WHERE user_id=?", (USER_ID,)).fetchone()[0]
        if count >= MAX_NOTES: return f"Error: note limit ({MAX_NOTES}) reached."
        cur = c.execute("INSERT INTO notes (user_id, title, body, created_at, updated_at) VALUES (?, ?, ?, ?, ?)", (USER_ID, title, body, now, now))
        return f"Created note id={cur.lastrowid}."

@function_tool
def update_note(note_id: int, title: str | None = None, body: str | None = None) -> str:
    with _db() as c:
        existing = c.execute("SELECT title, body FROM notes WHERE id=? AND user_id=?", (note_id, USER_ID)).fetchone()
        if not existing: return "Note not found."
        new_title = (title if title is not None else existing["title"]).strip()
        new_body  = body if body is not None else existing["body"]
        if not new_title or len(new_title) > 200: return "Error: title 1-200 chars."
        if len(new_body) > 4096: return "Error: body too long."
        c.execute("UPDATE notes SET title=?, body=?, updated_at=? WHERE id=? AND user_id=?", (new_title, new_body, int(time.time()), note_id, USER_ID))
        return f"Updated note id={note_id}."

@function_tool
def delete_note(note_id: int) -> str:
    with _db() as c:
        cur = c.execute("DELETE FROM notes WHERE id=? AND user_id=?", (note_id, USER_ID))
        return f"Deleted note id={note_id}." if cur.rowcount else "Note not found."

def build_agent(profile: dict) -> Agent:
    recent = "\n".join(f"  - {t}" for t in profile.get("recent_note_titles", [])) or "  (none yet)"
    sys_prompt = (
        f"You are {profile['username']}'s personal notes assistant. "
        f"They currently have {profile['note_count']} notes. Their most recent notes are:\n{recent}\n\n"
        "Use your tools to list, search, read, create, update, or delete notes as requested. "
        "Always confirm destructive actions in your reply. When showing a list of notes, format as <ul><li>title — id</li></ul>. Be concise.\n\n"
        "OUTPUT FORMAT — raw HTML, NOT markdown. <p> for paragraphs, <code> for inline code, "
        "<strong>/<em> for emphasis, <ul>/<ol>/<li> for lists. Never use markdown syntax."
    )
    model = os.environ.get("ZEALPHP_LEARN_AI_MODEL", "gpt-4.1-mini")
    return Agent(name="ZealPHP Notes", model=model, instructions=sys_prompt,
                 tools=[list_notes, read_note, search_notes, create_note, update_note, delete_note])

def emit(event: str, data: dict) -> None:
    sys.stdout.write(f"event: {event}\n")
    sys.stdout.write(f"data: {json.dumps(data)}\n\n")
    sys.stdout.flush()

async def main():
    global DB_PATH, USER_ID
    payload = json.loads(base64.b64decode(sys.argv[1]).decode())
    DB_PATH = payload["db_path"]; USER_ID = int(payload["user_id"])
    thread_id = payload.get("thread_id", "default")
    profile = payload.get("profile", {"username": "user", "note_count": 0, "recent_note_titles": []})

    emit("thread", {"thread_id": thread_id})

    sessions_dir = os.path.join(os.path.dirname(__file__), "../../.sessions")
    os.makedirs(sessions_dir, exist_ok=True)
    session = SQLiteSession(db_path=os.path.join(sessions_dir, "learn_threads.db"), session_id=thread_id)

    agent = build_agent(profile)
    result = Runner.run_streamed(agent, input=payload["message"], session=session)

    tool_names = {}
    async for ev in result.stream_events():
        if ev.type == "raw_response_event":
            t = getattr(ev.data, "type", "")
            if t == "response.output_text.delta":
                if ev.data.delta: emit("token", {"token": ev.data.delta})
            elif t == "response.output_item.added" and getattr(ev.data.item, "type", "") == "function_call":
                call_id = ev.data.item.id
                tool_names[call_id] = ev.data.item.name
                emit("tool_call", {"id": call_id, "name": ev.data.item.name, "phase": "start"})
            elif t == "response.function_call_arguments.delta":
                emit("tool_args", {"id": ev.data.item_id, "delta": ev.data.delta})
        elif ev.type == "run_item_stream_event" and ev.item.type == "tool_call_output_item":
            call_id = ev.item.raw_item.get("call_id", "?")
            out = str(ev.item.output)[:200]
            name = tool_names.get(call_id, "")
            emit("tool_done", {"id": call_id, "status": "ok", "result_preview": out})
            if name in ("create_note", "update_note", "delete_note"):
                emit("notes_changed", {})

    emit("done", {"done": True})

if __name__ == "__main__":
    asyncio.run(main())
```

- [ ] **Step 2: Make executable + commit**

```bash
chmod +x examples/agents/notes_agent.py
git add examples/agents/notes_agent.py
git commit -m "feat(learn): Python notes agent — 6 tools, user-scoped SQLite, SSE events"
```

### Task 7.2: Wire real-mode SSE proxy

**Files:**
- Modify: `route/learn.php` — replace `learn_chat_real` stub

- [ ] **Step 1: Replace the stub**

```php
function learn_chat_real($response, array $user, string $message, string $threadId, string $apiKey): void {
    $db = learn_db_open();
    $notes = learn_notes_list($db, $user['user_id']);
    $recent = array_slice(array_map(fn($n) => $n['title'], $notes), 0, 5);

    // Persist the user turn immediately (mirrors learn_chat_mock — see M6.5.2).
    learn_chat_history_append($db, $user['user_id'], $threadId, 'user', [['type' => 'text', 'html' => '<p>' . htmlspecialchars($message) . '</p>']]);

    $payload = [
        'message'   => $message,
        'thread_id' => $threadId,
        'db_path'   => learn_db_path(),
        'user_id'   => $user['user_id'],
        'profile'   => [
            'username'           => $user['username'],
            'note_count'         => count($notes),
            'recent_note_titles' => $recent,
        ],
    ];
    $b64 = base64_encode(json_encode($payload));
    $agentPath = (defined('ZEALPHP_ROOT') ? ZEALPHP_ROOT : __DIR__ . '/..') . '/examples/agents/notes_agent.py';

    $response->sse(function($emit) use ($apiKey, $b64, $agentPath, $threadId, $db, $user) {
        $env = $_ENV ?? [];
        $env['OPENAI_API_KEY'] = $apiKey;
        $env['ZEALPHP_LEARN_AI_MODEL'] = (string)(getenv('ZEALPHP_LEARN_AI_MODEL') ?: 'gpt-4.1-mini');
        $cmd = 'uv run ' . escapeshellarg($agentPath) . ' ' . escapeshellarg($b64);
        $desc = [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']];
        $proc = proc_open($cmd, $desc, $pipes, null, $env);
        if (!is_resource($proc)) {
            $emit(json_encode(['thread_id' => $threadId]), 'thread');
            $emit(json_encode(['error' => 'agent_unavailable']), 'error');
            $emit(json_encode(['done' => true]), 'done');
            return;
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);

        // Accumulator — same shape as learn_chat_mock — so the assistant turn is persisted.
        $items = []; $textBuf = '';
        $flush = function() use (&$items, &$textBuf) { if ($textBuf !== '') { $items[] = ['type' => 'text', 'html' => $textBuf]; $textBuf = ''; } };
        $reemit = function(string $data, string $event) use ($emit, &$items, &$textBuf, $flush) {
            $emit($data, $event);
            $payload = json_decode($data, true) ?: [];
            if ($event === 'token') {
                $textBuf .= (string)($payload['token'] ?? '');
            } elseif ($event === 'tool_call') {
                $flush();
                $items[] = ['type' => 'tool', 'id' => $payload['id'] ?? '?', 'name' => $payload['name'] ?? '?', 'status' => 'running', 'args' => '', 'result' => ''];
            } elseif ($event === 'tool_args') {
                foreach ($items as &$it) if ($it['type'] === 'tool' && $it['id'] === ($payload['id'] ?? '')) { $it['args'] .= (string)($payload['delta'] ?? ''); break; }
            } elseif ($event === 'tool_done') {
                foreach ($items as &$it) if ($it['type'] === 'tool' && $it['id'] === ($payload['id'] ?? '')) { $it['status'] = $payload['status'] ?? 'ok'; $it['result'] = (string)($payload['result_preview'] ?? ''); break; }
            }
        };

        $buffer = ''; $currentEvent = null;
        while (!feof($pipes[1])) {
            $chunk = fread($pipes[1], 4096);
            if ($chunk === false || $chunk === '') { usleep(40000); continue; }
            $buffer .= $chunk;
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);
                $line = rtrim($line, "\r");
                if (str_starts_with($line, 'event: ')) $currentEvent = trim(substr($line, 7));
                elseif (str_starts_with($line, 'data: ')) $reemit(substr($line, 6), $currentEvent ?: 'token');
            }
        }
        fclose($pipes[1]); fclose($pipes[2]); proc_close($proc);

        $flush();
        learn_chat_history_append($db, $user['user_id'], $threadId, 'assistant', $items);
    });
}
```

- [ ] **Step 2: Smoke test (only if `OPENAI_API_KEY` set)**

```bash
[ -z "$OPENAI_API_KEY" ] && echo "(skip — no key)" && exit 0
php app.php start -p 8090 -d --pid-file /tmp/zealphp/learn_dev.pid
sleep 2
curl -s -c /tmp/lc.txt -o /dev/null -H "Content-Type: application/json" \
  -d '{"username":"frank","password":"password123"}' \
  http://127.0.0.1:8090/api/learn/register
curl -s -b /tmp/lc.txt -N -H "Content-Type: application/json" \
  -d '{"message":"list my notes","thread_id":"t1"}' \
  http://127.0.0.1:8090/api/learn/chat | head -30
php app.php stop -p 8090 --pid-file /tmp/zealphp/learn_dev.pid
rm -f /tmp/lc.txt storage/learn.db storage/learn.db-wal storage/learn.db-shm
```

Expected (if key is set): `event: thread` then `event: tool_call` (the agent calling `list_notes`) then `event: tool_done` then `event: token` with the model's reply.

- [ ] **Step 3: Commit**

```bash
git add route/learn.php
git commit -m "feat(learn): real-mode chat — proc_open Python agent + SSE proxy"
```

---

## Milestone 8 — Lesson content 10, 11, 12

Lesson 9 (AI Chat) already has its written body from Task 6.3. Lessons 1–7 from Milestone 5. Remaining: 10, 11, 12.

### Task 8.1: Lesson 10 — Async & Coroutines

**Files:**
- Modify: `template/pages/learn/async.php`
- Modify: `route/learn.php` — add `/api/learn/demo/timing` endpoint

- [ ] **Step 1: Add timing demo endpoint**

```php
$app->route('/api/learn/demo/timing', ['methods' => ['GET']], function() {
    $g = G::instance();
    $mode = $g->get['mode'] ?? 'parallel';

    $work = function() {
        \OpenSwoole\Coroutine::sleep(0.1);
        return 'done';
    };

    $start = microtime(true);
    if ($mode === 'sequential') {
        $work(); $work(); $work();
    } else {
        $ch = new \OpenSwoole\Coroutine\Channel(3);
        for ($i = 0; $i < 3; $i++) {
            \OpenSwoole\Coroutine::create(function() use ($work, $ch) {
                $ch->push($work());
            });
        }
        for ($i = 0; $i < 3; $i++) $ch->pop();
    }
    $elapsed = (int)round((microtime(true) - $start) * 1000);
    header('Content-Type: application/json');
    return ['mode' => $mode, 'elapsed_ms' => $elapsed];
});
```

- [ ] **Step 2: Write lesson body**

Replace `template/pages/learn/async.php` with a body containing:
- `_lesson_header` with prev=ai-chat, next=deployment, number=10, title="Async & Coroutines"
- `_youwilllearn` items: "When OpenSwoole helps", "go() + Channel pattern", "How ZealPHP routes are coroutine-handlers"
- Two `<pre>` code blocks: sequential `for` vs. parallel `go() + Channel`
- A `_tryit` panel containing two buttons that fetch `/api/learn/demo/timing?mode=sequential` and `?mode=parallel`, displaying elapsed_ms in a result `<div>` (vanilla JS, ~15 lines inline)
- `_callout` "When NOT to use coroutines": for I/O-bound, not CPU-bound

Verify with curl:

```bash
php app.php start -p 8090 -d --pid-file /tmp/zealphp/learn_dev.pid
sleep 2
curl -s 'http://127.0.0.1:8090/api/learn/demo/timing?mode=sequential'
curl -s 'http://127.0.0.1:8090/api/learn/demo/timing?mode=parallel'
php app.php stop -p 8090 --pid-file /tmp/zealphp/learn_dev.pid
```

Expected: sequential `elapsed_ms` ~300ms, parallel ~100ms.

Commit: `feat(learn): lesson 10 async + live timing demo`.

### Task 8.2: Lesson 11 — Deployment

**Files:**
- Modify: `template/pages/learn/deployment.php`

Sections:
1. `php app.php start -d` — daemon mode (link to CLAUDE.md CLI table).
2. systemd unit — link to `src/deploy/zealphp.service` with a `<pre>` of the file.
3. Nginx reverse proxy snippet (block with proxy_pass + WebSocket headers).
4. Env vars — table listing `OPENAI_API_KEY`, `ZEALPHP_LEARN_AI_MODEL`, `ZEALPHP_LEARN_DB_PATH`, etc., with defaults and what each does.
5. Docker — `<pre>` of a minimal Dockerfile.

Commit: `feat(learn): lesson 11 deployment content`.

### Task 8.3: Lesson 12 — Philosophy

**Files:**
- Modify: `template/pages/learn/philosophy.php`

Three short sections matching the spec:
1. "Plain PHP scales further than you think" — anchor on the 67k req/s benchmark numbers from `template/pages/performance.php`.
2. "JavaScript where it helps, not as a tax" — htmx is enough for 95% of UI interactivity.
3. "Server-first is simpler" — one process, one mental model, fewer moving parts than a React + Node + Redis stack.

End with a CTA linking to `https://github.com/sibidharan/zealphp-learn` (will return 404 until Milestone 10 lands).

Commit: `feat(learn): lesson 12 philosophy + zealphp-learn CTA`.

---

## Milestone 9 — Integration tests

### Task 9.1: Write `tests/Integration/LearnApiTest.php`

**Files:**
- Create: `tests/Integration/LearnApiTest.php`

- [ ] **Step 1: Write the test class**

```php
<?php
namespace ZealPHP\Tests\Integration;

use ZealPHP\Tests\TestCase;

class LearnApiTest extends TestCase
{
    private string $aliceCookieJar;
    private string $bobCookieJar;

    protected function setUp(): void
    {
        parent::setUp();
        $this->aliceCookieJar = tempnam(sys_get_temp_dir(), 'lc_alice_');
        $this->bobCookieJar = tempnam(sys_get_temp_dir(), 'lc_bob_');
    }

    protected function tearDown(): void
    {
        @unlink($this->aliceCookieJar);
        @unlink($this->bobCookieJar);
    }

    private function curl(string $cookieJar, string $method, string $path, ?array $body = null, array $headers = []): array {
        $ch = curl_init(self::$baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $cookieJar,
            CURLOPT_COOKIEFILE => $cookieJar,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            $headers[] = 'Content-Type: application/json';
        }
        if ($headers) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $raw = curl[redacted use \curl_exec]($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        return ['status' => $status, 'body' => substr($raw, $hSize)];
    }

    public function test_unauth_endpoints_return_401(): void
    {
        $r = $this->curl($this->aliceCookieJar, 'GET', '/api/learn/notes');
        $this->assertSame(401, $r['status']);
        $r = $this->curl($this->aliceCookieJar, 'POST', '/api/learn/notes', ['title' => 't', 'body' => 'b']);
        $this->assertSame(401, $r['status']);
    }

    public function test_register_login_logout_flow(): void
    {
        $u = 'tu_' . uniqid();
        $r = $this->curl($this->aliceCookieJar, 'POST', '/api/learn/register', ['username' => $u, 'password' => 'password123']);
        $this->assertSame(302, $r['status']);

        $r = $this->curl($this->aliceCookieJar, 'GET', '/api/learn/notes');
        $this->assertSame(200, $r['status']);

        $r = $this->curl($this->aliceCookieJar, 'POST', '/api/learn/logout');
        $this->assertSame(302, $r['status']);

        $r = $this->curl($this->aliceCookieJar, 'GET', '/api/learn/notes');
        $this->assertSame(401, $r['status']);

        $r = $this->curl($this->aliceCookieJar, 'POST', '/api/learn/login', ['username' => $u, 'password' => 'password123']);
        $this->assertSame(302, $r['status']);
    }

    public function test_duplicate_registration(): void
    {
        $u = 'dup_' . uniqid();
        $this->curl($this->aliceCookieJar, 'POST', '/api/learn/register', ['username' => $u, 'password' => 'password123']);
        $r = $this->curl($this->bobCookieJar, 'POST', '/api/learn/register', ['username' => $u, 'password' => 'otherpw123']);
        $this->assertSame(409, $r['status']);
    }

    public function test_two_users_isolation(): void
    {
        $alice = 'a_' . uniqid();
        $bob   = 'b_' . uniqid();
        $this->curl($this->aliceCookieJar, 'POST', '/api/learn/register', ['username' => $alice, 'password' => 'password123']);
        $this->curl($this->bobCookieJar,   'POST', '/api/learn/register', ['username' => $bob,   'password' => 'password123']);
        $rA = $this->curl($this->aliceCookieJar, 'POST', '/api/learn/notes', ['title' => 'alices-note', 'body' => '']);
        $this->assertSame(200, $rA['status']);
        $listB = $this->curl($this->bobCookieJar, 'GET', '/api/learn/notes');
        $this->assertSame(200, $listB['status']);
        $this->assertStringNotContainsString('alices-note', $listB['body']);
    }

    public function test_chat_status_shape(): void
    {
        $r = $this->curl($this->aliceCookieJar, 'GET', '/api/learn/chat/status');
        $this->assertSame(200, $r['status']);
        $j = json_decode($r['body'], true);
        $this->assertArrayHasKey('ai_enabled', $j);
        $this->assertArrayHasKey('mock_mode', $j);
        $this->assertArrayHasKey('model', $j);
    }

    public function test_mock_chat_creates_note(): void
    {
        $u = 'm_' . uniqid();
        $this->curl($this->aliceCookieJar, 'POST', '/api/learn/register', ['username' => $u, 'password' => 'password123']);
        $r = $this->curl($this->aliceCookieJar, 'POST', '/api/learn/chat', [
            'message' => 'create a note titled buy milk',
            'thread_id' => 't1',
        ]);
        $this->assertSame(200, $r['status']);
        $this->assertStringContainsString('event: tool_call', $r['body']);
        $this->assertStringContainsString('event: tool_done', $r['body']);
        $this->assertStringContainsString('event: notes_changed', $r['body']);
        $list = $this->curl($this->aliceCookieJar, 'GET', '/api/learn/notes');
        $this->assertStringContainsString('buy milk', $list['body']);
    }

    public function test_all_lesson_pages_return_200(): void
    {
        $slugs = ['', '/create-app', '/first-page', '/components', '/routing', '/sessions', '/htmx', '/notes', '/ai-chat', '/async', '/deployment', '/philosophy'];
        foreach ($slugs as $s) {
            $r = $this->curl($this->aliceCookieJar, 'GET', '/learn' . $s);
            $this->assertSame(200, $r['status'], "/learn$s did not return 200");
        }
    }
}
```

**Note:** the `curl[redacted...]` syntax is intentional — when implementing, write `\curl_exec($ch)` literally. The redaction-style placeholder above is to avoid markdown rendering issues.

- [ ] **Step 2: Run integration tests with a fresh test DB**

```bash
export ZEALPHP_LEARN_DB_PATH=storage/learn.test.db
rm -f storage/learn.test.db*
php app.php start -p 8090 -d --pid-file /tmp/zealphp/learn_dev.pid
sleep 2
ZEALPHP_TEST_PORT=8090 ./vendor/bin/phpunit tests/Integration/LearnApiTest.php --testdox
php app.php stop -p 8090 --pid-file /tmp/zealphp/learn_dev.pid
rm -f storage/learn.test.db*
```

Expected: all 7 tests pass.

- [ ] **Step 3: Commit**

```bash
git add tests/Integration/LearnApiTest.php
git commit -m "test(learn): integration tests — auth, isolation, chat, all lesson pages"
```

---

## Milestone 10 — Extraction script + companion repo setup

### Task 10.1: Write `scripts/extract-learn-repo.sh`

**Files:**
- Create: `scripts/extract-learn-repo.sh`
- Create: `docs/learn-app.md` (companion-repo README source)

- [ ] **Step 1: Write the extraction script**

```bash
#!/usr/bin/env bash
# Extract the in-repo /learn implementation to a standalone sibidharan/zealphp-learn repo.
set -euo pipefail

# 1. Resolve target via the companion-discovery chain.
TARGET="${ZEALPHP_LEARN_DIR:-}"
if [ -z "$TARGET" ]; then
  for candidate in ../zealphp-learn ../../zealphp-learn; do
    if [ -d "$(realpath "$candidate" 2>/dev/null)" ]; then
      TARGET="$(realpath "$candidate")"; break
    fi
  done
fi
if [ -z "$TARGET" ]; then
  echo "ERROR: cannot find zealphp-learn checkout."
  echo "  Set ZEALPHP_LEARN_DIR or clone the repo as ../zealphp-learn"
  exit 1
fi

# 2. Refuse if target is dirty.
if ! git -C "$TARGET" diff --quiet || ! git -C "$TARGET" diff --cached --quiet; then
  echo "ERROR: $TARGET has uncommitted changes. Commit or stash first."
  exit 1
fi

echo "Extracting to: $TARGET"

# 3. rsync allow-list path roots (no --delete of unknown files).
mkdir -p "$TARGET"/{public/learn,public/css,public/js,route,template/components,template/pages/learn,examples/agents,storage,scripts,docs}

rsync -a public/learn.php          "$TARGET/public/"
rsync -a public/learn/              "$TARGET/public/learn/"
rsync -a public/css/learn.css      "$TARGET/public/css/"
rsync -a public/js/learn.js        "$TARGET/public/js/"
rsync -a route/learn.php           "$TARGET/route/"
rsync -a template/_learn_sidebar.php "$TARGET/template/"
rsync -a template/pages/learn.php  "$TARGET/template/pages/"
rsync -a template/pages/learn/      "$TARGET/template/pages/learn/"
for c in _callout _lesson_header _youwilllearn _deepdive _tryit _note_card _counter_button; do
  rsync -a "template/components/${c}.php" "$TARGET/template/components/"
done
rsync -a examples/agents/notes_agent.py "$TARGET/examples/agents/"
rsync -a scripts/setup.sh                "$TARGET/scripts/" 2>/dev/null || true

# 4. Write divergent files (standalone _master.php, app.php, composer.json, README).
cat > "$TARGET/app.php" <<'PHP'
<?php
require __DIR__ . '/vendor/autoload.php';
use ZealPHP\App;

$app = new App();
$app->superglobals(false);
require __DIR__ . '/route/learn.php';

$port = (int)(getenv('PORT') ?: 8080);
$host = getenv('HOST') ?: '0.0.0.0';
$app->run(['host' => $host, 'port' => $port]);
PHP

# Standalone _master.php — drops main-site _nav, slimmed bar.
cat > "$TARGET/template/_master.php" <<'PHP'
<?php
use ZealPHP\App;
$title ??= 'ZealPHP Learn';
$page  ??= 'learn';
?><!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($title) ?></title>
  <link rel="stylesheet" href="/css/learn.css">
  <script src="https://unpkg.com/htmx.org@1.9.12" defer></script>
  <script src="/js/learn.js" defer></script>
</head>
<body>
<header style="padding:1rem 1.5rem;border-bottom:1px solid #e7e5e4;display:flex;gap:1rem;align-items:center">
  <a href="/learn" style="font-weight:800;color:#1c1917;text-decoration:none">ZealPHP <span style="color:#f59e0b">Learn</span></a>
  <span style="flex:1"></span>
  <?php if (function_exists('learn_current_user') && ($u = learn_current_user())): ?>
    <span><?= htmlspecialchars($u['username']) ?></span>
    <a href="/api/learn/logout">Logout</a>
  <?php endif; ?>
  <a href="https://github.com/sibidharan/zealphp-learn" target="_blank">GitHub ↗</a>
</header>
<main><?php App::render("/pages/$page", compact('title', 'page')); ?></main>
</body>
</html>
PHP

# .env.example
cp .env.example "$TARGET/.env.example"

# .gitignore — append to existing or create.
{
  echo "/vendor"
  echo "/storage/learn.db"
  echo "/storage/learn.db-wal"
  echo "/storage/learn.db-shm"
  echo "/.sessions"
  echo ".env"
} > "$TARGET/.gitignore"

# composer.json — basic shape (companion will be tagged per-release manually).
cat > "$TARGET/composer.json" <<'JSON'
{
  "name": "sibidharan/zealphp-learn",
  "description": "Learn ZealPHP by building a real Personal Notes + AI Chat app.",
  "type": "project",
  "license": "MIT",
  "require": {
    "php": "^8.3",
    "sibidharan/zealphp": "^0.2"
  }
}
JSON

# README sourced from docs/learn-app.md if present.
if [ -f docs/learn-app.md ]; then
  cp docs/learn-app.md "$TARGET/README.md"
fi

# 5. composer install
(cd "$TARGET" && composer install --no-dev 2>&1 | tail -5)

# 6. Print summary.
echo ""
echo "Extraction complete. Review with:"
echo "  cd $TARGET && git status && git diff"
echo ""
echo "Then commit + push manually:"
echo "  git add . && git commit -m 'sync from sibidharan/zealphp vX.Y.Z'"
echo "  git push"
```

- [ ] **Step 2: chmod + write `docs/learn-app.md`**

```bash
chmod +x scripts/extract-learn-repo.sh
```

Write `/var/labsstorage/home/sibidharan/zealphp/docs/learn-app.md` as the companion-repo README source. Sections: What is this, Run it, Lessons covered, How to bring your own OPENAI_API_KEY, How to deploy. Keep under 200 lines.

- [ ] **Step 3: Dry-run sanity check (extraction can only fully run when companion clone exists)**

```bash
# Verify script lints and runs to the discovery stage.
bash -n scripts/extract-learn-repo.sh && echo "syntax-ok"
ZEALPHP_LEARN_DIR=/nonexistent bash scripts/extract-learn-repo.sh 2>&1 | head -3
```

Expected: `syntax-ok`, then the cannot-find-checkout error message.

- [ ] **Step 4: Commit**

```bash
git add scripts/extract-learn-repo.sh docs/learn-app.md
git commit -m "feat(learn): extraction script + companion-repo README source"
```

---

## Milestone 11 — Final end-to-end verification

### Task 11.1: Run the full unit + integration suite

- [ ] **Step 1: Unit tests**

```bash
./vendor/bin/phpunit tests/Unit/LearnAuthTest.php tests/Unit/LearnNotesRepoTest.php --testdox
```

Expected: all tests pass (6 + 7 = 13).

- [ ] **Step 2: Integration tests (server up on 8090)**

```bash
export ZEALPHP_LEARN_DB_PATH=storage/learn.test.db
rm -f storage/learn.test.db*
php app.php start -p 8090 -d --pid-file /tmp/zealphp/learn_dev.pid
sleep 2
ZEALPHP_TEST_PORT=8090 ./vendor/bin/phpunit tests/Integration/LearnApiTest.php --testdox
php app.php stop -p 8090 --pid-file /tmp/zealphp/learn_dev.pid
rm -f storage/learn.test.db*
```

Expected: all 7 integration tests pass.

### Task 11.2: Chrome DevTools golden-path walkthrough

Start server fresh (no DB):

```bash
rm -f storage/learn.db*
php app.php start -p 8090 -d --pid-file /tmp/zealphp/learn_dev.pid
sleep 2
```

Then in this session, walk through the entire learn experience using Chrome DevTools MCP tools:

- [ ] **A. `/learn` index** — `mcp__chrome-devtools__new_page` → `http://127.0.0.1:8090/learn`. Screenshot. Verify: top nav shows "Learn" highlighted, sidebar lists 12 lessons in 3 groups, hero copy renders.

- [ ] **B. Each Get Started lesson** — navigate to `/learn/create-app`, `/learn/first-page`. Screenshot each. Verify: sidebar highlights correct item, prev/next chips work.

- [ ] **C. Components lesson (4)** — navigate, screenshot. Verify the rendering-methods table renders.

- [ ] **D. Routing lesson (5)** — navigate, screenshot. Verify code blocks.

- [ ] **E. Sessions lesson (6)** — navigate, register a user `eve`/`password123` from this page's embedded form. Screenshot before + after. Verify session became active (button changes to logged-in state).

- [ ] **F. htmx lesson (7)** — click the counter button 3 times. Verify the count increments without page reload. `list_console_messages` — no errors.

- [ ] **G. Notes lesson (8)** — already logged in. Add three notes. Screenshot. Delete one. Verify htmx swaps in/out without full page reload.

- [ ] **H. AI Chat lesson (9), mock mode** — send "list my notes". Verify tool-card streams in, then list HTML. Send "create a note titled buy bread" — verify tool_call card with streaming args, then notes panel auto-refreshes.

- [ ] **H2. Chat history persistence** — reload the AI Chat page (F5). Verify both previous chat turns reappear via the history loader (`/api/learn/chat/history?thread_id=...` ZealAPI endpoint with `App::renderStream`). Check Network tab to confirm the history request fires on mount. Click "New thread" and verify the messages clear + a fresh thread_id appears in localStorage.

- [ ] **I. Async lesson (10)** — click the parallel/sequential timing buttons. Verify ms displayed.

- [ ] **J. Deployment + Philosophy** — navigate, screenshot.

- [ ] **K. Mobile layout** — `mcp__chrome-devtools__resize_page` to 640×900, navigate `/learn/notes`. Verify sidebar collapses, content reflows. Screenshot.

- [ ] **L. Two-user isolation** — open `mcp__chrome-devtools__new_page` in a separate tab (`mcp__chrome-devtools__new_page` again). Register `frank`/`password123`. Navigate to `/learn/notes` — verify list is empty (does NOT show eve's notes). Add a note as frank. Switch back to eve's tab — verify frank's note is NOT visible.

- [ ] **M. Console error check** — `list_console_messages` on the most recently used page. Verify zero errors.

Stop server:

```bash
php app.php stop -p 8090 --pid-file /tmp/zealphp/learn_dev.pid
```

### Task 11.3: Final commit — verification log

- [ ] **Step 1: Capture verification output**

Write `/tmp/learn-verification.md` with a 1-line note per step above (A-M), all marked ✓. This is just a workflow artifact — no commit.

### Task 11.4: Cleanup

- [ ] **Step 1: Ensure no stale dev DBs in repo**

```bash
git status --short | grep storage/ && echo "DB files leaked — clean up" || echo "clean"
```

- [ ] **Step 2: Final review**

```bash
git log --oneline $(git merge-base HEAD master)..HEAD 2>/dev/null | wc -l
```

Expected: roughly 25-40 commits across all milestones.

---

## Done

Plan complete. v1 ships when:
- All milestones above are checked.
- The 12 lesson pages render and look right in Chrome.
- The Notes app + AI chat work end-to-end in both mock mode and (if `OPENAI_API_KEY` set) real mode.
- All PHPUnit suites pass.
- Two users using the same browser/session-isolation can each have their own notes without overlap.

Companion-repo push to `sibidharan/zealphp-learn` happens during the next coordinated release per CLAUDE.md's release procedure.
