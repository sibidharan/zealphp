<?php
use ZealPHP\App;
$title       ??= 'ZealPHP';
$description ??= 'The async PHP framework built on OpenSwoole.';
$page        ??= 'home';
$active      ??= $page;
?>
<!doctype html>
<html lang="en">
<?php App::render('/_head', compact('title', 'description', 'page')); ?>
<body hx-boost="true">
<?php App::render('/_nav', ['active' => $active]); ?>
<?php App::render('/components/_banner'); ?>
<main class="page-body">
<?php App::render("/pages/$page", get_defined_vars()); ?>
</main>
<?php App::render('/_footer'); ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js"></script>
<script>
function initPageScripts(root) {
  (root || document).querySelectorAll('pre code').forEach(el => {
    if (el.dataset.highlighted) return;
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

  // Learn sidebar is hx-preserved across swaps so scroll position stays put.
  // Re-sync its active-state + page <title> with the current URL after each
  // navigation (htmx swaps .learn-layout, which doesn't touch <head>).
  const sb = document.getElementById('learn-sidebar');
  if (sb) {
    sb.querySelectorAll('li.active').forEach(li => li.classList.remove('active'));
    sb.querySelectorAll('.learn-substeps').forEach(el => el.remove());
    const link = sb.querySelector('a[href="' + location.pathname + '"]');
    if (link) {
      const li = link.closest('li');
      li?.classList.add('active');
      const lessonName = (link.firstChild?.textContent || link.textContent).trim();
      if (lessonName) document.title = 'ZealPHP Learn · ' + lessonName + ' · ZealPHP';
      // Inject auto-generated substeps under the active item. Scans the
      // current lesson's .lesson-content for <h2> elements; auto-slugifies
      // any missing ids so anchor links work. Only the active lesson gets
      // a substep list — active state IS the expand state, no toggle UI.
      const article = document.querySelector('.lesson-content');
      if (article && li) {
        const headings = Array.from(article.querySelectorAll('h2'));
        const ul = document.createElement('ul');
        ul.className = 'learn-substeps';
        let added = 0;
        headings.forEach(h2 => {
          if (!h2.id) {
            const slug = h2.textContent.toLowerCase()
              .replace(/^\s*\d+\.\s*/, '')
              .replace(/[^a-z0-9\s-]/g, '')
              .trim().replace(/\s+/g, '-').slice(0, 60);
            if (slug) h2.id = slug;
          }
          if (!h2.id) return;
          let label = h2.textContent.replace(/^\s*\d+\.\s*/, '').trim();
          const dashIdx = label.search(/\s+[—–-]\s+/);
          if (dashIdx > 0 && dashIdx < 40) label = label.slice(0, dashIdx).trim();
          const liEl = document.createElement('li');
          const a = document.createElement('a');
          a.href = '#' + h2.id;
          a.textContent = label || h2.textContent.trim();
          liEl.appendChild(a);
          ul.appendChild(liEl);
          added++;
        });
        if (li.dataset.demo) {
          const liEl = document.createElement('li');
          liEl.className = 'learn-substep-demo';
          const a = document.createElement('a');
          a.href = '/demo/view/' + li.dataset.demo;
          a.target = '_blank';
          a.rel = 'noopener';
          a.appendChild(document.createTextNode('Open standalone '));
          const arrow = document.createElement('span');
          arrow.style.fontWeight = '600';
          arrow.textContent = '↗';
          a.appendChild(arrow);
          liEl.appendChild(a);
          ul.appendChild(liEl);
          added++;
        }
        if (added > 0) li.appendChild(ul);
      }
    }
  }

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
}
document.addEventListener('DOMContentLoaded', () => initPageScripts());
document.addEventListener('htmx:afterSettle', () => initPageScripts());

// hx-preserve keeps the sidebar DOM element identity across swaps, but htmx
// detaches/reattaches it during the swap and the browser zeroes scrollTop on
// reinsertion. Snapshot before, restore after.
(function () {
  let savedScrollTop = 0;
  document.addEventListener('htmx:beforeSwap', () => {
    const sb = document.getElementById('learn-sidebar');
    if (sb) savedScrollTop = sb.scrollTop;
  });
  document.addEventListener('htmx:afterSettle', () => {
    const sb = document.getElementById('learn-sidebar');
    if (sb && savedScrollTop > 0) sb.scrollTop = savedScrollTop;
  });
})();
</script>
</body>
</html>
