# Learn Chain Audit — Implementation Plan

**Goal:** Sweep every learn lesson for logical-sense bugs in prev/next chips, lesson numbering, and in-body cross-references. The user spotted one ("ai-chat says tic-tac-toe is the previous lesson, but it's actually the next") — find the rest.

## Findings

### Lesson HEADER chain — clean ✓

All 25 lessons have `prev`/`next` chips in their `_lesson_header` call that match the sidebar (`template/_learn_sidebar.php`) order: 1→2→3→…→25. Verified programmatically against the canonical sidebar manifest. No fixes needed at the chip layer.

### Lesson NUMBERING — clean ✓

Each lesson's `'number'` matches its position in the sidebar (after the cross-server-chat insertion already renumbered 22→23→24→25 for routing/async/deployment). The main `learn.php` index has `'number' => 1` and no `prev` field (correct — it's the first lesson). All others have both fields, all consistent.

### In-body cross-references — TWO bugs found

| File | Line | Wrong | Right | Why it's wrong |
|---|---|---|---|---|
| `template/pages/learn/ai-chat.php` | 180-181 | `ws_tictactoe_broadcast() and ws_session_counter_broadcast()` "from the previous lesson" | Drop the tictactoe mention from the "previous lesson" cite | ai-chat is Lesson 20; tic-tac-toe is Lesson 21 (NEXT). The session-counter broadcaster IS from the previous lesson (websocket, 19). The text accidentally drags tic-tac-toe into a "previous lesson" claim. This is the bug the user reported. |
| `template/pages/learn/websocket.php` | 45 | "Lesson **23**, Async Patterns" | "Lesson **24**, Async Patterns" | `learn/async` was Lesson 23 before the cross-server-chat insertion bumped it to 24. The in-prose cite went stale. |

All other "Lesson NN" references audited:
- Lesson 11 → streaming ✓ (in `responses.php`)
- Lesson 13 → components ✓ (in `first-page.php`, `notes.php`)
- Lesson 16 → sessions ✓ (in `mental-model.php`)
- Lesson 18 → notes ✓ (in `auth.php`, `components.php`)
- Lesson 19 → websocket ✓ (in `notes.php`, `sessions.php`, `cross-server-chat.php`)
- Lesson 20 → ai-chat ✓ (in `components.php`, `notes.php`, `htmx.php`)
- Lessons 22-25 inserted/renumbered cleanly because I updated `tictactoe.next`, `routing.prev`, `routing/async/deployment.number` as part of the cross-server-chat commit. Only websocket.php:45 was missed because it cites async by number, not slug.

### Cross-link anchors — clean ✓

Every `href="/learn/<slug>"` in lesson bodies points to an existing slug. No 404s would result from clicking through.

### Lesson 1 (`learn.php`) prev field — intentional ✓

`learn.php` has no `'prev'` field. That's correct — it's the first lesson, prev would be the marketing site. The `_lesson_header` component handles a missing `prev` gracefully (chip just doesn't render).

## Tasks

### Task 1: Fix ai-chat.php tic-tac-toe forward-reference (the user-reported bug)

**File:** `template/pages/learn/ai-chat.php:177-182`

Current text:
```
WS::broadcast() is the same shape as
ws_tictactoe_broadcast() and
ws_session_counter_broadcast() from the previous lesson — iterate the per-fd
Store table, push to every fd whose stored key matches:
```

Cleanest fix: drop the tic-tac-toe reference. It's a forward cite squeezed into a backward sentence; removing it is simpler than rewriting the surrounding paragraph. The session-counter broadcaster IS the previous lesson's broadcast pattern, which is the whole point.

New text:
```
WS::broadcast() is the same shape as
ws_session_counter_broadcast() from the previous lesson — iterate the per-fd
Store table, push to every fd whose stored key matches:
```

### Task 2: Fix websocket.php async lesson number (numbering rot from insertion)

**File:** `template/pages/learn/websocket.php:45`

Current: `<a href="/learn/async">Lesson 23, Async Patterns</a>`
Fix: `<a href="/learn/async">Lesson 24, Async Patterns</a>`

### Task 3: Verify

- [ ] Restart server, hit `/learn/ai-chat` and `/learn/websocket`, confirm 200.
- [ ] Curl the page bodies and grep for the corrected text — old wording should be gone, new wording present.
- [ ] Sanity check: the htmx swap endpoint serves both pages too.
- [ ] One final pass: grep `template/pages/learn/*.php` for `Lesson [0-9]+` and eyeball that every number matches the current sidebar order.

### Task 4: Commit

Single commit, message:
```
docs(learn): fix stale lesson cross-references after cross-server-chat insert

Two in-body cross-references went stale after Lesson 22 (cross-server-chat)
was inserted between tic-tac-toe and routing:

  - ai-chat.php (Lesson 20) referred to ws_tictactoe_broadcast() as
    "from the previous lesson" — but tic-tac-toe is the NEXT lesson (21),
    not the previous. Dropped the tic-tac-toe mention; the session-counter
    broadcaster from websocket (Lesson 19, the actual previous lesson)
    stays. This is the user-reported bug.
  - websocket.php (Lesson 19) cited async as "Lesson 23" — async is now
    Lesson 24 after the cross-server-chat renumber. Bumped.

All other in-body Lesson NN references audited and verified consistent
with the current sidebar order. No header chain / numbering issues found
(both clean after the prior renumber pass).
```

## Out of scope

- **No new lessons.** This is a correctness pass only.
- **No prose rewrites** beyond the two cited bugs. The lesson chain audit found these two; touching anything else risks introducing new churn.
- **No CHANGELOG entry.** Doc-correctness fixes don't get release notes.
