<?php use ZealPHP\App;
$user = \ZealPHP\Learn\Auth::currentUser();
$active = $active ?? 'learn/ai-chat';
?>
<div class="learn-layout">
  <?php App::render('/_learn_sidebar', ['active' => $active]); ?>
  <article class="lesson-content">
    <?php App::render('/components/_lesson_header', [
      'number' => 9, 'title' => 'Add AI Chat',
      'subtitle' => 'A chat that can read AND modify your notes — streamed via SSE with live tool calls.',
      'prev' => ['slug' => 'learn/notes', 'title' => 'Build Personal Notes'],
      'next' => ['slug' => 'learn/websocket', 'title' => 'WebSocket'],
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
        'body' => '<p><a href="/learn/notes">Register or log in</a> first — the chat needs to know whose notes to act on.</p>',
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
      </section>

      <h2>How this works</h2>
      <p>The architecture has four stages. Each is a few lines of code:</p>
      <ol>
        <li>Your browser <strong>POSTs</strong> to <code>/api/learn/chat</code> (a ZealAPI file).</li>
        <li>ZealPHP <strong>spawns</strong> a Python subprocess (<code>proc_open</code>) running the OpenAI Agents SDK.</li>
        <li>Python <strong>streams</strong> events (tokens, tool calls, tool results) to stdout as SSE lines.</li>
        <li>PHP <strong>re-emits</strong> each line as a Server-Sent Event. The browser renders tokens and tool cards live.</li>
      </ol>

      <h3>SSE streaming with <code>$response->sse()</code></h3>
      <p>ZealPHP's SSE helper sets the right headers (<code>Content-Type: text/event-stream</code>, <code>Cache-Control: no-cache</code>) and gives you an <code>$emit</code> callback that writes SSE-formatted lines:</p>
      <pre><code class="language-php">$response->sse(function($emit) {
    $emit(json_encode(['token' => 'Hello']), 'token');
    // Sends: event: token\ndata: {"token":"Hello"}\n\n
    usleep(100000);
    $emit(json_encode(['token' => ' world']), 'token');
    $emit(json_encode(['done' => true]), 'done');
});</code></pre>

      <h3>The SSE event protocol</h3>
      <p>Six event types flow from server to browser. The frontend JS switches on the event name:</p>
      <pre><code class="language-text">event: thread       data: {"thread_id":"abc"}
event: token        data: {"token":"<p>Sure, I'll do that.</p>"}
event: tool_call    data: {"id":"call_1","name":"create_note","phase":"start"}
event: tool_args    data: {"id":"call_1","delta":"{\"title\":\"Buy"}
event: tool_done    data: {"id":"call_1","status":"ok","result_preview":"id: 3"}
event: notes_changed data: {}
event: done         data: {"done":true}</code></pre>
      <p>The browser builds a timeline: text fragments interleaved with tool cards. <code>notes_changed</code> triggers an htmx refresh of the notes panel on the left.</p>

      <h3>The Python agent</h3>
      <p>The agent at <code>examples/agents/notes_agent.py</code> uses the OpenAI Agents SDK with six <code>@function_tool</code> decorators — one for each CRUD operation plus search. All tools are scoped by <code>USER_ID</code> (set server-side, never from the model):</p>
      <pre><code class="language-python">@function_tool
def create_note(title: str, body: str) -> str:
    """Create a new note for the user."""
    with _db() as c:
        cur = c.execute(
            "INSERT INTO notes (user_id, title, body, created_at, updated_at) "
            "VALUES (?, ?, ?, ?, ?)",
            (USER_ID, title, body, now, now),
        )
        return f"Created note id={cur.lastrowid}."</code></pre>
      <p>The PHP handler spawns this via <code>proc_open</code>, reads stdout line-by-line, and re-emits each SSE event through <code>$response->sse()</code>:</p>
      <pre><code class="language-php">// api/learn/chat.php (simplified)
$response->sse(function($emit) use ($cmd) {
    $proc = proc_open($cmd, [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    $buffer = '';
    while (!feof($pipes[1])) {
        $chunk = fread($pipes[1], 4096);
        if ($chunk === '' || $chunk === false) { usleep(40000); continue; }
        $buffer .= $chunk;
        while (($pos = strpos($buffer, "\n")) !== false) {
            $line = substr($buffer, 0, $pos);
            $buffer = substr($buffer, $pos + 1);
            if (str_starts_with($line, 'event: ')) $event = trim(substr($line, 7));
            elseif (str_starts_with($line, 'data: ')) $emit(substr($line, 6), $event);
        }
    }
    proc_close($proc);
});</code></pre>

      <?php App::render('/components/_callout', [
        'variant' => 'info',
        'title'   => 'Mock mode',
        'body'    => '<p>If <code>OPENAI_API_KEY</code> is not set, the server runs a rule-based mock that mutates your notes in response to phrases like <em>create a note titled X</em> and <em>delete X</em>. The mock emits the same SSE events as the real model, so the timeline UI works either way. The chat header badge shows which mode you\'re in.</p>',
      ]); ?>

      <h3>Chat history (ZealAPI + renderToString)</h3>
      <p>Every turn is persisted to SQLite via <code>ChatHistory::append()</code>. When the page loads, the JS fetches <code>/api/learn/chat_history?thread_id=...</code> — a ZealAPI file that renders each historical message as an HTML bubble using <code>App::renderToString('/components/_chat_history_bubble', ...)</code>.</p>

      <?php App::render('/components/_deepdive', [
        'title' => 'Why pipe Python instead of calling the OpenAI API directly from PHP?',
        'body'  => '<p>The OpenAI Agents SDK (Python) handles tool dispatch, conversation memory (<code>SQLiteSession</code>), and streaming out of the box. Replicating that in PHP would be hundreds of lines. By spawning a subprocess, ZealPHP stays thin — it\'s a streaming proxy, not an AI framework. This pattern also lets you swap the agent (different model, different tools) without touching the PHP layer.</p>',
      ]); ?>
    <?php endif; ?>

    <div class="lesson-chips">
      <a class="lesson-chip lesson-chip-prev" href="/learn/notes" hx-get="/api/learn/page?slug=learn/notes" hx-target=".learn-layout" hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/learn/notes">← Build Personal Notes</a>
      <a class="lesson-chip lesson-chip-next" href="/learn/websocket" hx-get="/api/learn/page?slug=learn/websocket" hx-target=".learn-layout" hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/learn/websocket">WebSocket →</a>
    </div>
  </article>
</div>
