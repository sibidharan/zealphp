<?php use ZealPHP\App;
$user = \ZealPHP\Learn\Auth::currentUser();
$active = $active ?? 'learn/ai-chat';
?>
<div class="learn-layout">
  <?php App::render('/_learn_sidebar', ['active' => $active]); ?>
  <article class="lesson-content">
    <?php App::render('/components/_lesson_header', [
      'number' => 10, 'title' => 'AI Chat',
      'subtitle' => 'An assistant that reads and modifies your notes — streamed token by token.',
      'prev' => ['slug' => 'learn/notes', 'title' => 'Personal Notes'],
      'next' => ['slug' => 'learn/websocket', 'title' => 'Real-Time Sync'],
    ]); ?>

    <?php App::render('/components/_youwilllearn', ['items' => [
      'What Server-Sent Events (SSE) are and how they differ from HTTP',
      'Stream events from ZealPHP with $response->sse()',
      'How tool calls let an AI agent modify your data',
      'The architecture: PHP spawns Python, Python streams SSE, browser renders live',
    ]]); ?>

    <h2>The problem</h2>
    <p>
      Your notes app works, but the user does everything manually. What if they could talk to an
      assistant that <strong>reads, searches, creates, and deletes their notes</strong>? And what if the
      response <strong>streamed in token by token</strong>, like ChatGPT, instead of making the user
      wait for the full answer?
    </p>

    <h2>What is SSE?</h2>
    <p>
      Regular HTTP is like <strong>texting</strong>: you send a message, get a reply, conversation over.
      Server-Sent Events is like <strong>calling someone and saying "read me the news"</strong>. They
      talk continuously, you listen. You can't interrupt (that would be WebSocket). But for streaming
      AI tokens, you don't need to — you just need to listen.
    </p>
    <p>On the wire, SSE looks like this:</p>
    <pre><code class="language-text">event: token
data: {"token":"Hello"}

event: token
data: {"token":" world"}

event: done
data: {"done":true}</code></pre>
    <p>Each event is two lines (<code>event:</code> + <code>data:</code>) followed by a blank line. The browser receives them one by one as they arrive.</p>

    <h2>Streaming with <code>$response->sse()</code></h2>
    <p>ZealPHP makes SSE a one-liner. The <code>sse()</code> helper sets the right headers and gives you an <code>$emit</code> callback:</p>
    <pre><code class="language-php">$response->sse(function($emit) {
    $emit(json_encode(['token' => 'Hello']), 'token');
    usleep(100000);
    $emit(json_encode(['token' => ' world']), 'token');
    $emit(json_encode(['done' => true]), 'done');
});</code></pre>

    <h2>The architecture</h2>
    <pre class="mermaid">sequenceDiagram
    participant B as Browser
    participant PHP as ZealPHP
    participant PY as Python Agent
    participant AI as OpenAI API
    participant API as Notes API
    B->>PHP: POST /api/learn/chat
    PHP->>PY: proc_open (spawn)
    PY->>AI: Runner.run_streamed()
    AI-->>PY: token deltas
    PY-->>PHP: SSE: event: token
    PHP-->>B: SSE: token (streamed live)
    AI->>PY: tool_call: create_note
    PY->>API: POST /api/learn/notes
    API-->>PY: {"id": 42}
    Note over API: WS::broadcast()
    API-->>B: WebSocket: note_changed
    PY-->>PHP: SSE: tool_done
    PHP-->>B: SSE: tool_done + notes_changed</pre>

    <?php if (!$user): ?>
      <?php App::render('/components/_callout', [
        'variant' => 'warn', 'title' => 'Log in to use the chat',
        'body' => '<p><a href="/learn/auth">Register or log in</a> first — the chat needs to know whose notes to act on.</p>',
      ]); ?>
    <?php else: ?>
      <p>Logged in as <strong><?= htmlspecialchars($user['username']) ?></strong>.</p>
      <section class="chat">
        <div>
          <h3 style="margin-top:0">Your notes</h3>
          <div id="notes-list" class="notes-list" hx-get="/api/learn/notes" hx-trigger="load" hx-swap="innerHTML">
            <p class="notes-empty">Loading…</p>
          </div>
        </div>
        <div>
          <div id="learn-chat" class="chat-box" data-thread-id="">
            <div class="chat-head">
              Notes assistant
              <span class="chat-mode">…</span>
              <button type="button" class="chat-new" title="Start a fresh conversation">New thread</button>
            </div>
            <div class="chat-scroll">
              <div class="chat-history"></div>
              <div class="chat-messages"></div>
            </div>
            <form class="chat-form" autocomplete="off" hx-boost="false">
              <input type="text" name="message" placeholder="Ask anything about your notes…" required>
              <button type="submit">Send</button>
            </form>
          </div>
          <div class="event-log-wrap">
            <h4 class="event-log-title">Event log</h4>
            <div id="ws-log" class="event-log"></div>
          </div>
        </div>
      </section>

      <?php App::render('/components/_callout', [
        'variant' => 'success',
        'title'   => 'Watch the Event Log',
        'body'    => '<p>Try: <em>"create a note titled shopping list"</em>. Watch the Event Log below the chat — you\'ll see <span style="background:#3b82f6;color:#fff;padding:0 .3rem;border-radius:3px;font-size:.75rem;font-weight:700">SSE</span> events (tool_call, tool_done, notes_changed) stream in as the AI works. Then check the notes panel on the left — the new note appears with a green glow, pushed via <span style="background:#a855f7;color:#fff;padding:0 .3rem;border-radius:3px;font-size:.75rem;font-weight:700">WS</span> broadcast. Open a second tab to see cross-tab sync.</p>',
      ]); ?>
    <?php endif; ?>

    <h2>Tool calls</h2>
    <p>
      The AI doesn't just generate text. It can <strong>call functions</strong> that interact with your
      data. The <a href="https://github.com/sibidharan/zealphp/blob/master/examples/agents/notes_agent.py" target="_blank">Python agent</a> defines six tools: <code>list_notes</code>, <code>read_note</code>,
      <code>search_notes</code>, <code>create_note</code>, <code>update_note</code>, <code>delete_note</code>.
    </p>
    <p>
      When the model decides to use a tool, the browser shows an expandable card with the tool name,
      arguments, and result. You see the AI's reasoning process in real time.
    </p>

    <?php App::render('/components/_callout', [
      'variant' => 'info',
      'title'   => 'Mock mode',
      'body'    => '<p>If <code>OPENAI_API_KEY</code> is not set, the server runs a rule-based mock that responds to phrases like "create a note titled X" and "delete X." The mock emits the same SSE events as the real model, so the timeline UI works either way. The header badge shows which mode you\'re in.</p>',
    ]); ?>

    <h2>Introducing <code>App::renderStream()</code></h2>
    <p>
      SSE is one form of streaming. ZealPHP also supports <strong>streaming HTML</strong> — sending
      chunks of a page as they're generated, rather than waiting for the entire page to render.
    </p>
    <pre><code class="language-php">// A streaming template
return function($items) {
    yield "&lt;section&gt;";
    foreach ($items as $item) {
        yield "&lt;div&gt;{$item->name}&lt;/div&gt;";
    }
    yield "&lt;/section&gt;";
};</code></pre>
    <p>
      <code>App::renderStream()</code> returns a Generator. Each <code>yield</code> is flushed to the
      browser immediately. For AI responses, database-heavy pages, or any slow render, this means the
      user sees content arriving progressively instead of staring at a blank screen.
    </p>

    <?php App::render('/components/_concept_check', [
      'id'       => 'sse1',
      'question' => 'Why use SSE instead of returning JSON after the full response is ready?',
      'correct'  => 'a',
      'explain'  => 'SSE sends data as it becomes available. The user sees tokens arriving in real time instead of waiting 5-10 seconds for the full response. It makes slow operations feel instant.',
      'options'  => [
        'a' => 'Users see tokens arriving in real time instead of waiting for the full response',
        'b' => 'SSE uses less bandwidth than JSON',
        'c' => 'JSON cannot represent streaming data',
      ],
    ]); ?>

    <?php App::render('/components/_deepdive', [
      'title' => 'Why pipe Python instead of calling OpenAI from PHP?',
      'body'  => '<p>The OpenAI Agents SDK (Python) handles tool dispatch, conversation memory, and streaming out of the box. Replicating that in PHP would be hundreds of lines. By spawning a subprocess, ZealPHP stays thin — it\'s a streaming proxy, not an AI framework. This pattern lets you swap the agent (different model, different tools) without touching PHP.</p>',
    ]); ?>

    <?php App::render('/components/_keytakeaways', ['items' => [
      'SSE streams events from server to browser — perfect for AI token streaming',
      '<code>$response->sse($emit)</code> handles headers and formatting automatically',
      'Tool calls let AI agents interact with your data — shown as expandable cards',
      '<code>App::renderStream()</code> streams HTML chunks for progressive page rendering',
    ]]); ?>

    <div class="lesson-chips">
      <a class="lesson-chip lesson-chip-prev" href="/learn/notes"
         hx-get="/api/learn/page?slug=learn/notes" hx-target=".learn-layout"
         hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/learn/notes">← Personal Notes</a>
      <a class="lesson-chip lesson-chip-next" href="/learn/websocket"
         hx-get="/api/learn/page?slug=learn/websocket" hx-target=".learn-layout"
         hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/learn/websocket">Real-Time Sync →</a>
    </div>
  </article>
</div>
