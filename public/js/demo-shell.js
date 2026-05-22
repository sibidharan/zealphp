/* ==========================================================================
   demo-shell.js — behavior for the standalone demo viewer shell
   (template/components/_demo_shell.php). Extracted verbatim from the inline
   <script> block that lived in that component (separation-of-concerns refactor).
   Loaded deferred from the component's own <head>.
   ========================================================================== */

// Code highlighting once highlight.js loads. The demo route handlers emit
// either <pre><code class="language-…">…</code></pre> OR bare
// <pre class="demo-payload">…</pre>. For the bare form, wrap the content
// in a <code> element so hljs.highlightElement() can work on it. Also
// adds a copy button matching the site's lesson-pre convention.
window.addEventListener('load', () => {
  if (!window.hljs) return;
  document.querySelectorAll('pre.demo-payload').forEach(pre => {
    if (pre.querySelector('code')) return;             // already wrapped
    const text = pre.textContent;
    const code = document.createElement('code');
    code.textContent = text;
    // Heuristic: PHP if dollar-var, php-open, or arrow-op appears;
    // JSON if it begins with { or [; JS if const/let/function( appears.
    let lang = 'plaintext';
    if (/^\s*[\{\[]/.test(text))                       lang = 'json';
    else if (/->|::|\$\w+|<\?php/.test(text))          lang = 'php';
    else if (/\b(const|let|function\s*\()/.test(text)) lang = 'javascript';
    code.className = 'language-' + lang;
    pre.textContent = '';
    pre.appendChild(code);
  });
  document.querySelectorAll('pre code').forEach(el => {
    if (el.dataset.highlighted) return;
    try { hljs.highlightElement(el); } catch (_) {}
    const pre = el.closest('pre');
    if (!pre || pre.querySelector('.code-copy')) return;
    const btn = document.createElement('button');
    btn.className = 'code-copy';
    btn.textContent = 'copy';
    btn.addEventListener('click', () => {
      navigator.clipboard.writeText(el.textContent).then(() => {
        btn.textContent = 'copied!';
        btn.classList.add('copied');
        setTimeout(() => { btn.textContent = 'copy'; btn.classList.remove('copied'); }, 1200);
      });
    });
    pre.style.position = pre.style.position || 'relative';
    pre.appendChild(btn);
  });
});
