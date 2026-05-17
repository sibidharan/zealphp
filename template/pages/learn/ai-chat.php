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

    <h2 id="step-overview">1. Overview — what you&rsquo;re building</h2>
    <p>
      Your notes app works, but the user does everything manually. By the end of this lesson, they
      can <strong>talk to an assistant</strong> that reads their notes, searches them, and creates
      or deletes them on command. The reply <strong>streams token by token</strong>, like ChatGPT,
      so a slow model never feels frozen.
    </p>
    <p>
      Five pieces, in build order:
    </p>
    <ol>
      <li><strong>The PHP-to-Python bridge</strong> — why we spawn a subprocess instead of calling OpenAI from PHP, and how the wire protocol works.</li>
      <li><strong>The streaming proxy route</strong> — one PHP route that opens an SSE connection and forwards whatever the Python process emits.</li>
      <li><strong>The chat UI</strong> — a form + an <code>EventSource</code> client that appends tokens as they arrive.</li>
      <li><strong>Tool calls</strong> — the AI doesn&rsquo;t just talk; it calls your existing notes API. Same endpoints the UI uses.</li>
      <li><strong>The live event log</strong> — a side panel that shows every SSE event in real time, so you can <em>see</em> the protocol working.</li>
    </ol>
    <p>
      The widget at <a href="#step-tryit">step 6</a> is the finished product; the sections in between
      are the build.
    </p>

    <h2 id="step-components">2. Component extraction — the chat widget</h2>
    <p>
      Like the Notes lesson, the entire chat UI — left-side notes panel, center chat box, right-side
      event log — is extracted into a reusable partial that the lesson page and the standalone popup
      both render:
    </p>
    <pre><code class="language-php">// template/components/_chat_widget.php
&lt;?php
$user ??= null;
if (!$user) return;
?&gt;
&lt;section class="chat"&gt;
  &lt;div&gt;&lt;h3 class="chat-h"&gt;Your notes&lt;/h3&gt;
       &lt;div id="notes-list" class="notes-list" hx-get="/api/learn/notes" hx-trigger="load"&gt;…&lt;/div&gt;&lt;/div&gt;
  &lt;div&gt;&lt;div id="learn-chat" class="chat-box"&gt;…&lt;/div&gt;
       &lt;div class="event-log-wrap"&gt;&lt;div id="ws-log" class="event-log"&gt;&lt;/div&gt;&lt;/div&gt;&lt;/div&gt;
&lt;/section&gt;</code></pre>
    <p>
      Same as Notes, two consumers: this lesson page calls
      <code>App::render('/components/_chat_widget', ['user' =&gt; $user])</code> at
      <a href="#step-tryit">step 6</a>; the standalone shell at
      <code>/demo/view/chat/widget</code> renders the same partial inside <code>_demo_shell.php</code>.
      The widget output is identical — <code>#learn-chat</code> and <code>#ws-log</code> ids stay
      stable so <code>/js/learn.js</code> wires up SSE + the event-log identically in both contexts.
    </p>

    <h2 id="step-sse">3. Server-Sent Events — what is it?</h2>
    <p>
      Regular HTTP is like <strong>texting</strong>: you send a message, get a reply, conversation over.
      Server-Sent Events is like <strong>calling someone and saying &ldquo;read me the news&rdquo;</strong>. They
      talk continuously, you listen. You can&rsquo;t interrupt (that would be WebSocket). But for streaming
      AI tokens, you don&rsquo;t need to — you just need to listen.
    </p>
    <p>On the wire, SSE looks like this:</p>
    <pre><code class="language-text">event: token
data: {"token":"Hello"}

event: token
data: {"token":" world"}

event: done
data: {"done":true}</code></pre>
    <p>Each event is two lines (<code>event:</code> + <code>data:</code>) followed by a blank line. The browser receives them one by one as they arrive.</p>
    <p>
      ZealPHP makes SSE a one-liner. The <code>$response-&gt;sse()</code> helper sets the right
      headers and gives you an <code>$emit</code> callback:
    </p>
    <pre><code class="language-php">$response->sse(function($emit) {
    $emit(json_encode(['token' => 'Hello']), 'token');
    usleep(100000);
    $emit(json_encode(['token' => ' world']), 'token');
    $emit(json_encode(['done' => true]), 'done');
});</code></pre>

    <h2 id="step-agent">4. Python agent bridge</h2>
    <p>
      The OpenAI Agents SDK is Python-only. It handles conversation memory, tool dispatch, and token
      streaming &mdash; hundreds of lines of logic ZealPHP doesn&rsquo;t want to reimplement. So
      <strong>PHP stays thin and Python does the AI work</strong>. The PHP side spawns a Python
      process per chat request, forwards the prompt on stdin, and reads SSE-formatted events from
      the subprocess&rsquo;s stdout.
    </p>
    <p>
      This is the same CGI-bridge pattern <code>cgi_worker.php</code> uses for legacy PHP files: parent
      process forks a child, child writes a stream of bytes back through a pipe, parent forwards to
      the client. The protocol is plain text &mdash; you can <code>cat</code> the python script&rsquo;s
      output and read it.
    </p>

    <h2 id="step-stream">5. Streaming + tool calls — the architecture</h2>
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

    <p>
      The AI doesn&rsquo;t just generate text. It can <strong>call functions</strong> that interact
      with your data. The <a href="https://github.com/sibidharan/zealphp/blob/master/examples/agents/notes_agent.py" target="_blank">Python agent</a> defines six tools: <code>list_notes</code>, <code>read_note</code>,
      <code>search_notes</code>, <code>create_note</code>, <code>update_note</code>, <code>delete_note</code>.
      When the model decides to use a tool, the agent emits a <code>tool_call</code> SSE event; the
      result comes back as a <code>tool_done</code> event. The browser shows an expandable card with
      the tool name, arguments, and result — you see the AI&rsquo;s reasoning process in real time.
    </p>

    <?php App::render('/components/_callout', [
      'variant' => 'info',
      'title'   => 'Mock mode',
      'body'    => '<p>If <code>OPENAI_API_KEY</code> is not set, the server runs a rule-based mock that responds to phrases like "create a note titled X" and "delete X." The mock emits the same SSE events as the real model, so the timeline UI works either way. The header badge shows which mode you\'re in.</p>',
    ]); ?>

    <h2 id="step-tryit">6. Try it — the live chat widget</h2>
    <?php if (!$user): ?>
      <?php App::render('/components/_callout', [
        'variant' => 'warn', 'title' => 'Log in to use the chat',
        'body' => '<p><a href="/learn/auth">Register or log in</a> first — the chat needs to know whose notes to act on.</p>',
      ]); ?>
    <?php else: ?>
      <?php App::render('/components/_chat_widget', ['user' => $user]); ?>
      <a class="lesson-popout-cta" href="/demo/view/chat/widget" target="_blank" rel="noopener">
        Open this chat in a new tab ↗
      </a>
      <?php App::render('/components/_callout', [
        'variant' => 'success',
        'title'   => 'Watch the Event Log',
        'body'    => '<p>Try: <em>"create a note titled shopping list"</em>. Watch the Event Log below the chat — you&rsquo;ll see <span class="proto-badge sse">SSE</span> events (tool_call, tool_done, notes_changed) stream in as the AI works. Then check the notes panel on the left — the new note appears with a green glow, pushed via <span class="proto-badge ws">WS</span> broadcast. Open the popout for cross-tab sync from a clean window.</p>',
      ]); ?>
    <?php endif; ?>

    <h2>What you built — and the streaming shape underneath it</h2>
    <p>
      Look at what's running on this page right now:
    </p>
    <ul>
      <li>A chat box that posts to <code>/api/learn/chat</code> and reads an SSE stream back.</li>
      <li>A Python subprocess that the PHP route spawned, currently sitting on a pipe waiting for the next prompt.</li>
      <li>A notes list on the left that updates the moment the AI calls <code>create_note</code> &mdash;
        because that tool hits <code>/api/learn/notes</code>, which broadcasts a WebSocket event,
        which every open tab listens for. (You'll wire that up in the next lesson.)</li>
      <li>An event log that visualizes every <span class="proto-badge sse">SSE</span> and <span class="proto-badge ws">WS</span> message as it arrives.</li>
    </ul>
    <p>
      The whole thing is &lt; 200 lines of PHP plus the Python agent script. No queue worker, no
      Redis, no message broker, no separate Node service for streaming. One <code>php app.php</code>
      process. That's the headline.
    </p>

    <h2>Bonus — <code>App::renderStream()</code></h2>
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
         hx-get="/api/learn/page?slug=learn/notes" hx-target=".lesson-content"
         hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/learn/notes">← Personal Notes</a>
      <a class="lesson-chip lesson-chip-next" href="/learn/websocket"
         hx-get="/api/learn/page?slug=learn/websocket" hx-target=".lesson-content"
         hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/learn/websocket">Real-Time Sync →</a>
    </div>
  </article>
</div>
