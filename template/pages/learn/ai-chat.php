<?php use ZealPHP\App;
$user = function_exists('learn_current_user') ? learn_current_user() : null;
$active = $active ?? 'learn/ai-chat';
?>
<div class="learn-layout">
  <?php App::render('/_learn_sidebar', ['active' => $active]); ?>
  <article class="lesson-content">
    <?php App::render('/components/_lesson_header', [
      'number' => 9, 'title' => 'Add AI Chat',
      'subtitle' => 'A chat that can read AND modify your notes — streamed via SSE with live tool calls.',
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
          <div class="chat-history" hidden></div>
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

      <?php App::render('/components/_callout', [
        'variant' => 'info',
        'title'   => 'Mock mode',
        'body'    => '<p>If <code>OPENAI_API_KEY</code> is not set, the server runs a rule-based mock that mutates your notes in response to phrases like <em>create a note titled X</em> and <em>delete X</em>. The mock emits the same SSE events as the real model, so the timeline UI works either way. The chat header badge shows which mode you\'re in.</p>',
      ]); ?>

      <h2>The chat handler in 50 lines</h2>
      <p>Stream events as the Python agent emits them; re-emit each <code>data:</code> line as SSE; track tool-mutating events so we can also push <code>notes_changed</code> to refresh the list panel:</p>
      <pre><code>function learn_chat_real($response, array $user, string $message, string $threadId, string $apiKey): void {
    $payload = ['message' =&gt; $message, 'thread_id' =&gt; $threadId, 'user_id' =&gt; $user['user_id'], /* + profile */];
    $cmd = 'uv run examples/agents/notes_agent.py ' . escapeshellarg(base64_encode(json_encode($payload)));
    $response-&gt;sse(function($emit) use ($cmd) {
        $proc = proc_open($cmd, [...pipes...], $pipes);
        while (!feof($pipes[1])) {
            $line = trim(fgets($pipes[1]));
            if (str_starts_with($line, 'event: ')) $currentEvent = trim(substr($line, 7));
            elseif (str_starts_with($line, 'data: ')) $emit(substr($line, 6), $currentEvent);
        }
        proc_close($proc);
    });
}</code></pre>
    <?php endif; ?>

    <div class="lesson-chips">
      <a class="lesson-chip lesson-chip-prev" href="/learn/notes">← Build Personal Notes</a>
      <a class="lesson-chip lesson-chip-next" href="/learn/async">Async & Coroutines →</a>
    </div>
  </article>
</div>
