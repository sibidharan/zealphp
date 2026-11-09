<?php
/**
 * The home page chat's canned reply when no OPENAI_API_KEY is set.
 * route/chat.php streams it word by word as SSE tokens (split on spaces, so
 * it's one line: a newline would ride inside a token).
 */
?>
<p>I'm a demo running on ZealPHP's SSE streaming. This response is being streamed token-by-token using <code>$response->sse()</code>.</p><p>To enable real AI responses, set the <code>OPENAI_API_KEY</code> environment variable. The backend uses the <strong>OpenAI Agents SDK</strong> with <code>SQLiteSession</code> for conversation threads — the SDK remembers your entire conversation automatically.</p><p>ZealPHP makes this a 5-line feature, not a 50-line infrastructure project.</p>
