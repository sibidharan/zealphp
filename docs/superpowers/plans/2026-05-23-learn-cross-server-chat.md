# Learn: Cross-Server Chat Lesson — Implementation Plan

**Goal:** Add ONE new lesson to the "Build the App" track of `/learn` that walks the reader from a single-server WebSocket chat to one that survives across N OpenSwoole servers, exercising the v0.2.39 primitives: pluggable Store backend, `Store::publish` + `App::onPubSub`, and the `WSRouter` helper.

**Architecture:** Lesson is a single PHP template at `template/pages/learn/cross-server-chat.php` (~350 LOC, modeled after `learn/tictactoe.php`). Wired in via a one-line addition to `template/_learn_sidebar.php` and a 3-line public-route stub at `public/learn/cross-server-chat.php`. The previous lesson's `next` link is updated. No new `src/` code, no new demo routes — the existing `/demo/pubsub/*` endpoints already exercise the underlying machinery.

**Tech Stack:** PHP 8.3, OpenSwoole, `ZealPHP\Store`, `ZealPHP\App::onPubSub`, `ZealPHP\WSRouter`, `OpenSwoole\Http\Response`. Reuses the chat skeleton from `learn/websocket`.

---

## Why now

| Surface | Story |
|---|---|
| `/pubsub` portfolio page | ✓ exists — shows the cross-server WS routing code |
| `/ws#scaling` callout | ✓ exists |
| `/learn/websocket` | covers single-server WS, ends with a callout pointing here |
| **Learn-chain hands-on lesson** | **gap** — the marquee v0.2.39 feature has no step-by-step walkthrough |

`learn/tictactoe.php` is the existing Store-focused hands-on experiment (18 `Store::`/`Counter::` calls, multiplayer game). It covers single-box shared state. A second generic-Store experiment would duplicate it. The cross-server WS lesson is qualitatively different: it's the *only* learn-chain content that touches the pub/sub primitive and the only place that demonstrates running TWO instances of `php app.php` cooperating.

## File map

| File | Action | Purpose |
|---|---|---|
| `template/pages/learn/cross-server-chat.php` | **Create** | The lesson content (~350 LOC) |
| `public/learn/cross-server-chat.php` | **Create** | 3-line `App::render('_master', …)` stub — URL → template mapping |
| `template/_learn_sidebar.php` | **Modify** | Add lesson to "Build the App" group |
| `template/pages/learn/tictactoe.php` | **Modify** | Update `next` link to point to the new lesson |

No `src/` changes. No new demo routes. No test churn.

## Lesson outline (sections, in order)

1. **Hook — the problem** (1 short para + ASCII diagram). Single-server chat works. Add a second `php app.php` on `:9090`. User A on `:8080`, user B on `:9090`. A sends → B never sees it. Why?
2. **Mental model: `$fd` is process-local.** Each OpenSwoole worker holds its own `$fd → connection` table. Only the owning worker on the owning process can `$server->push($fd, …)`. A `$fd=12` on server 1 is meaningless on server 2.
3. **The three primitives.** One short code block + 1 sentence each:
   - **Shared Store backend.** `Store::defaultBackend(StoreBackendKind::Redis)` — flips storage from local Table to Redis with zero handler changes.
   - **An ownership map.** A Store table `ws_owner` keyed by `client_id`, columns `server_id` + `fd`.
   - **A per-server inbox.** `App::onPubSub("ws:server:$myId", …)` — each server subscribes to its own identity channel.
4. **Build it — 4 bite-sized steps with full working code:**
   - Step 1: Switch the Store backend (one line in `app.php`).
   - Step 2: Claim ownership in `onOpen`, release in `onClose`.
   - Step 3: Register the per-server subscriber that delivers routed messages locally.
   - Step 4: Replace direct `$server->push()` with the `sendToClient($id, $payload)` helper that does the lookup + publish.
5. **Try it live.** Two-instance recipe: `php app.php start -p 8080` + `php app.php start -p 9090`, both with `ZEALPHP_STORE_BACKEND=redis` (Valkey at `:16379` works for local validation). Open two browser tabs, watch the message cross.
6. **The `WSRouter` shortcut.** Five-call API:
   ```php
   WSRouter::init();        // one-time boot wiring
   WSRouter::own($id, $fd);  // onOpen
   WSRouter::release($id);   // onClose
   WSRouter::sendToClient($id, $payload);  // anywhere
   WSRouter::broadcast($channel, $payload); // room fan-out
   ```
   Side-by-side comparison: the manual 4-step build vs the 5-call helper.
7. **Beyond two servers — broadcast rooms.** `App::onPubSub("chat:room:42", $handler)` — fan-out to every connected client in a room across every node.
8. **Production note.** Driver choice (both validated; phpredis ~2× faster on CRUD; HOOK_ALL nuance). Sticky-or-not load balancer (works either way — Store routes by client_id, not by source IP). Recovery story: if the owning server dies, the `ws_owner` row is stale until the client reconnects; an optional TTL on the table handles this gracefully (note in the lesson, not a build step).
9. **Key takeaways + next lesson.** Three bullets:
   - `$fd` is process-local; cross-server delivery needs a routing fabric.
   - `Store::publish` is fire-and-forget; `Store::publishReliable` (Streams) is at-least-once.
   - `WSRouter` bundles the pattern — use the helper for new code; the manual version is for understanding what's underneath.
   Next: `learn/routing` (existing chain target after tictactoe).

## Tasks

### Task 1: Update sidebar manifest

**Files:** `template/_learn_sidebar.php:31-36` (the `'Build the App'` group)

- [ ] **Step 1:** Insert one row after the tictactoe entry:

```php
['learn/cross-server-chat', 'Cross-Server Chat', 'scale + pubsub'],
```

(No `data-demo` slug — the lesson includes its own "two-port recipe" instead of a popout demo, because cross-server can't be demonstrated from a single popped-out widget on one process.)

- [ ] **Step 2:** Restart the dev server (`php app.php restart`). Hit `/learn/store` and confirm the sidebar shows the new entry in "Build the App" with the chip rendered.

### Task 2: Create the public-route stub

**Files:** `public/learn/cross-server-chat.php` (new)

- [ ] **Step 1:** Write the 3-line wrapper following the established pattern:

```php
<?php use ZealPHP\App;
App::render('_master', [
    'title'  => 'ZealPHP · Cross-Server Chat',
    'page'   => 'learn/cross-server-chat',
    'active' => 'learn/cross-server-chat',
]);
```

- [ ] **Step 2:** Restart the server. `curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8080/learn/cross-server-chat` must return `200`. (At this point the template doesn't exist yet — `App::render` will throw. Verify the error is "template not found", not a routing miss.)

### Task 3: Write the lesson template

**Files:** `template/pages/learn/cross-server-chat.php` (new, ~350 LOC)

- [ ] **Step 1:** Scaffold the file with the standard learn-page header:

```php
<?php use ZealPHP\App; ?>
<div class="learn-layout">
  <?php App::render('/_learn_sidebar', ['active' => 'learn/cross-server-chat']); ?>
  <article class="lesson-content">
    <?php App::render('/components/_lesson_header', [
      'number'   => 22,  // increment past tictactoe (21)
      'title'    => 'Cross-Server Chat',
      'subtitle' => 'Take a single-server WebSocket chat and scale it to N OpenSwoole servers — the marquee v0.2.39 feature.',
      'next'     => ['slug' => 'learn/routing', 'title' => 'Routes & APIs'],
    ]); ?>

    <?php App::render('/components/_youwilllearn', ['items' => [
      'Why $fd is process-local and what that means for scaling',
      'How Store::publish + App::onPubSub form a routing fabric',
      'The ws_owner pattern: a shared map of client → server',
      'How WSRouter bundles the whole pattern in five calls',
    ]]); ?>
```

- [ ] **Step 2:** Write Section 1 (the hook). Single paragraph framing the problem, followed by an ASCII or mermaid diagram of two servers behind a load balancer with the broken message path drawn.

- [ ] **Step 3:** Write Section 2 (mental model). One paragraph: "`$fd` is a per-process integer handle into OpenSwoole's worker-local connection table. Worker A's `$fd=12` is not the same handle on worker B, and on a different OpenSwoole *process* the integer is meaningless." Inline code block showing `var_dump($server->getClientInfo($fd))` returning false on a different process.

- [ ] **Step 4:** Write Section 3 (three primitives). One `<h3>` per primitive with a 3-5 line code block underneath:
  - **Shared Store backend**: `Store::defaultBackend(StoreBackendKind::Redis)`.
  - **Ownership map**: `Store::make('ws_owner', 4096, ['server' => [Store::TYPE_STRING, 64]])`.
  - **Per-server inbox**: `App::onPubSub("ws:server:$myId", function ($payload) { ... })`.

- [ ] **Step 5:** Write Section 4 (build it). Four `<h3>` sub-sections, each with full code that compiles. The starting point is the chat from `learn/websocket` (the reader has it open). The 4 deltas are isolated and small.

- [ ] **Step 6:** Write Section 5 (try it live). Code block of the exact commands:

  ```bash
  ZEALPHP_STORE_BACKEND=redis ZEALPHP_REDIS_URL=redis://127.0.0.1:16379 php app.php start -p 8080
  ZEALPHP_STORE_BACKEND=redis ZEALPHP_REDIS_URL=redis://127.0.0.1:16379 php app.php start -p 9090
  ```

  Followed by the verification steps (open two tabs, send a message, watch it cross).

- [ ] **Step 7:** Write Section 6 (WSRouter shortcut). Side-by-side table comparing the manual 4-step build vs the helper's 5 calls. Code block of the helper version.

- [ ] **Step 8:** Write Section 7 (broadcast rooms). One short code block showing `App::onPubSub("chat:room:42", $handler)` + `Store::publish("chat:room:42", $payload)`.

- [ ] **Step 9:** Write Section 8 (production note). Callout block with:
  - Driver choice (both validated; cross-link `/store#phpredis-pubsub-caveat`).
  - Sticky-or-not LB (works either way).
  - Owner row staleness (mitigated by TTL or `App::onWorkerStop` cleanup).

- [ ] **Step 10:** Write Section 9 (key takeaways) and close the article tag.

### Task 4: Update tictactoe's next link

**Files:** `template/pages/learn/tictactoe.php`

- [ ] **Step 1:** Grep for `next.*learn/routing` in tictactoe; update to point to `learn/cross-server-chat`.

- [ ] **Step 2:** Restart and click through: tictactoe → "Next" → cross-server-chat. Confirm.

### Task 5: Verify

- [ ] **Step 1:** Restart the dev server one final time.
- [ ] **Step 2:** Hit `/learn/cross-server-chat`, `/learn/tictactoe`, `/learn/websocket`, `/learn/store`. All 200, no PHP errors, no broken layout.
- [ ] **Step 3:** Click the lesson from the sidebar (htmx swap path) — must render correctly.
- [ ] **Step 4:** Anchor checks: cross-links from `/pubsub` to `/learn/websocket#cross-server-routing` and from `/ws#scaling` resolve. The new lesson cross-links forward to `/pubsub` and `/store#phpredis-pubsub-caveat`.
- [ ] **Step 5:** PHPStan: `./vendor/bin/phpstan analyse template/ public/learn/cross-server-chat.php --no-progress` (template-level analysis — should be clean since lessons are HTML+PHP echo with no business logic).

### Task 6: Commit

- [ ] **Step 1:** Stage the new + modified files (`template/pages/learn/cross-server-chat.php`, `public/learn/cross-server-chat.php`, `template/_learn_sidebar.php`, `template/pages/learn/tictactoe.php`). Skip the unrelated `template/_nav.php` row-split diff.

- [ ] **Step 2:** Single commit:

  ```
  docs(learn): cross-server chat lesson — Store + pub/sub + WSRouter

  Adds Lesson 22 to the Build the App track: walks the reader from a
  single-server WebSocket chat to one that survives across N OpenSwoole
  servers, exercising the v0.2.39 primitives end-to-end:

    - Store::defaultBackend(StoreBackendKind::Redis) — backend switch
    - ws_owner Store table — client → server mapping
    - App::onPubSub("ws:server:$myId", …) — per-server inbox
    - WSRouter helper — bundles the pattern in 5 calls

  Closes the learn-chain gap for the marquee v0.2.39 feature. The
  /pubsub portfolio page and /ws#scaling callout already cover the
  what; this lesson covers the build-it-yourself how.

  Files:
   - template/pages/learn/cross-server-chat.php (new, ~350 LOC)
   - public/learn/cross-server-chat.php (new, route stub)
   - template/_learn_sidebar.php (+1 row in Build the App group)
   - template/pages/learn/tictactoe.php (next link updated)
  ```

## Verification recipe

| Check | Command | Expected |
|---|---|---|
| Public URL resolves | `curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8080/learn/cross-server-chat` | `200` |
| Sidebar shows entry | `curl -s http://127.0.0.1:8080/learn/store \| grep -c 'Cross-Server Chat'` | `≥ 1` |
| Tictactoe next link | `curl -s http://127.0.0.1:8080/learn/tictactoe \| grep -c 'learn/cross-server-chat'` | `≥ 1` |
| No PHP errors | `grep -c 'PHP.*Error\|Warning' /tmp/zealphp/server.log` after each curl | `0` |

## Out of scope

- **No new `src/` code** — `WSRouter` already exists and works.
- **No new demo routes** — `/demo/pubsub/*` already exists.
- **No popout widget** — cross-server can't be demoed in one widget on one process; the "two `php app.php` recipe" is the demo.
- **No CHANGELOG / version bump** — this is post-v0.2.40 docs polish.
- **No README addition** — README already mentions cross-node messaging; adding a "see /learn/cross-server-chat" pointer is optional polish, defer.

## Execution shape

Five tasks total, all small. ~30 minutes of focused work. Single commit. The lesson is a docs change only; no `src/` touched, no test impact.
