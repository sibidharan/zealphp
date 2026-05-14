<?php
use ZealPHP\App;
$title       ??= 'ZealPHP';
$description ??= 'The async PHP framework built on OpenSwoole.';
$page        ??= 'home';
$active      ??= $page;
?>
<!doctype html>
<html lang="en">
<?php App::render('/_head', compact('title', 'description')); ?>
<body>
<?php App::render('/_nav', ['active' => $active]); ?>
<main class="page-body">
<?php App::render("/pages/$page", compact('title', 'description', 'page', 'active')); ?>
</main>
<?php App::render('/_footer'); ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('pre code').forEach(el => {
    hljs.highlightElement(el);
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
    pre.appendChild(btn);
  });

  // Generic demo panel runner
  document.querySelectorAll('[data-demo-url]').forEach(btn => {
    const panel = document.getElementById(btn.dataset.target);
    const load = async () => {
      if (panel) panel.innerHTML = '<span class="demo-loading">Loading…</span>';
      try {
        const res = await fetch(btn.dataset.demoUrl);
        const ct  = res.headers.get('content-type') || '';
        let text;
        if (ct.includes('json')) {
          const j = await res.json();
          text = JSON.stringify(j, null, 2);
        } else {
          text = await res.text();
        }
        if (panel) panel.innerHTML = '<pre>' + text.replace(/</g,'&lt;') + '</pre>';
      } catch(e) {
        if (panel) panel.innerHTML = '<span style="color:red">Error: ' + e.message + '</span>';
      }
    };
    btn.addEventListener('click', load);
    // Auto-run on page load
    if (btn.dataset.autorun !== undefined) load();
  });

  // Tab switching
  document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      const group = btn.closest('.tabs').dataset.group;
      document.querySelectorAll(`[data-group="${group}"] .tab-btn`).forEach(b => b.classList.remove('active'));
      document.querySelectorAll(`[data-panel-group="${group}"] .tab-panel`).forEach(p => p.classList.remove('active'));
      btn.classList.add('active');
      document.getElementById(btn.dataset.tab)?.classList.add('active');
    });
  });
});
</script>
</body>
</html>
