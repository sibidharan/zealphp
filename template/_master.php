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
<div id="htmx-progress" class="htmx-progress" aria-hidden="true"></div>
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
      if (lessonName) {
        const section = location.pathname.startsWith('/docs') ? 'Docs' : 'Learn';
        document.title = 'ZealPHP ' + section + ' · ' + lessonName + ' · ZealPHP';
      }
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
          // body has hx-boost="true" which intercepts every <a> for an
          // htmx swap. For pure same-page anchor jumps we need a plain
          // browser navigation so the URL fragment + scroll lands cleanly.
          a.setAttribute('hx-boost', 'false');
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
        // Wire scrollspy: as the user scrolls past each <h2>, the matching
        // substep in the sidebar gets a .current highlight.
        setupSubstepScrollspy(li, headings);
      }
      // Hard-load case (e.g. /learn/tictactoe — lesson 21, far below the
      // sidebar viewport): the sidebar's scrollTop starts at 0, so the
      // active item is off-screen and the reader can't see where they are
      // in the curriculum. Center it. Called AFTER substep injection because
      // injected substeps grow li.active's measured height — centering on
      // the short bare-li would land off-by-N once the substeps expanded.
      // The htmx:afterSettle restore IIFE below calls the same helper after
      // restoring saved scroll, fixing "user clicked a far-away lesson"
      // (where the restored scrollTop puts them where they were, not where
      // the new active is).
      ensureActiveSidebarVisible(sb);
    }
  }

  // Scrollspy for the active lesson's substeps. Implementation: a single
  // scroll listener, throttled via requestAnimationFrame, recomputes which
  // <h2> is at the top of the viewport on every frame. We tried
  // IntersectionObserver first — it works for smooth scrolling, but instant
  // jumps (clicking a substep link, programmatic scrollTo) don't trigger
  // intersection-state changes for elements that go straight from
  // out-of-view to out-of-view, so the highlight gets stuck.
  function setupSubstepScrollspy(activeLi, headings) {
    if (window.__substepCleanup) {
      window.__substepCleanup();
      window.__substepCleanup = null;
    }
    if (!activeLi || !headings || !headings.length) return;
    const links = new Map();
    activeLi.querySelectorAll('.learn-substeps a[href^="#"]').forEach(a => {
      links.set(a.getAttribute('href').slice(1), a);
    });
    if (!links.size) return;
    let currentId = null;
    const setCurrent = (id) => {
      if (id === currentId) return;
      currentId = id;
      activeLi.querySelectorAll('.learn-substeps a.current').forEach(a => a.classList.remove('current'));
      const a = links.get(id);
      if (a) a.classList.add('current');
    };
    // Recompute the active substep. Pick the LAST <h2> in document order
    // whose top has scrolled past the active line (just below the page
    // nav). Two edge cases:
    //   - Page top: no h2 has crossed yet → fall through to first h2
    //   - Page bottom: the last h2 may never reach the line because the
    //     page can't scroll that far → if we're within 20px of page
    //     bottom, force-pick the last h2
    const recompute = () => {
      const lineY = 80;
      let pick = null;
      for (const h of headings) {
        if (h.getBoundingClientRect().top - lineY <= 0) pick = h;
        // Don't break — keep scanning so we pick the LAST h2 past the
        // line, not the first.
      }
      if (!pick) pick = headings[0];
      const docH = document.documentElement.scrollHeight;
      if (window.scrollY + window.innerHeight >= docH - 20) {
        pick = headings[headings.length - 1];
      }
      setCurrent(pick.id);
    };
    let rafPending = false;
    const onScroll = () => {
      if (rafPending) return;
      rafPending = true;
      requestAnimationFrame(() => {
        rafPending = false;
        recompute();
      });
    };
    window.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('resize', onScroll, { passive: true });
    recompute();  // initial state
    window.__substepCleanup = () => {
      window.removeEventListener('scroll', onScroll);
      window.removeEventListener('resize', onScroll);
    };
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

// Center the sidebar's active <li> in its own scroll viewport if it's
// off-screen. Used by initPageScripts (hard load) and the scroll-restore
// IIFE below (after htmx swap restores user's prior scroll position, which
// may not include the new active item).
function ensureActiveSidebarVisible(sb) {
  const li = sb.querySelector('li.active');
  if (!li) return;
  const sbR = sb.getBoundingClientRect();
  const liR = li.getBoundingClientRect();
  if (liR.top < sbR.top || liR.bottom > sbR.bottom) {
    sb.scrollTop += (liR.top - sbR.top) - (sbR.height - liR.height) / 2;
  }
}

// hx-preserve keeps the sidebar DOM element identity across swaps, but htmx
// detaches/reattaches it during the swap and the browser zeroes scrollTop on
// reinsertion. Snapshot before, restore after — then re-center the active
// item if the restore left it off-screen (e.g. user clicked a far-away lesson
// from the bottom of the sidebar; their old scroll position is irrelevant to
// the new active item).
(function () {
  let savedScrollTop = 0;
  document.addEventListener('htmx:beforeSwap', () => {
    const sb = document.getElementById('learn-sidebar');
    if (sb) savedScrollTop = sb.scrollTop;
  });
  document.addEventListener('htmx:afterSettle', () => {
    const sb = document.getElementById('learn-sidebar');
    if (!sb) return;
    if (savedScrollTop > 0) sb.scrollTop = savedScrollTop;
    ensureActiveSidebarVisible(sb);
  });
})();

// Top-of-page progress bar for in-flight htmx requests. NProgress / Linear /
// Vercel-docs pattern: a 2px amber strip animated via CSS transform.
// Two-threshold behavior so it works on BOTH fast and slow connections:
//
//   - Sub-SHOW_AFTER ms request (fast — fiber, cached): bar never appears.
//     Flashing a partial loader for a 50ms request feels janky and worse than
//     no indicator at all. Major docs sites (Linear, Vercel) do the same.
//   - Request still pending after SHOW_AFTER: bar fades in at "start" (30%),
//     then creeps to "mid" (75%) over 1.4s for the "working on it" feel.
//   - Request completes: bar snaps to "done" (100%) and fades out.
//
// SHOW_AFTER tuning: 150ms is the threshold below which users perceive an
// action as "instant" (Jakob Nielsen). Above that they want feedback.
(function () {
  const bar = document.getElementById('htmx-progress');
  if (!bar) return;
  const SHOW_AFTER = 150;   // ms before showing the bar at all
  const CREEP_AFTER = 380;  // ms before transitioning start → mid
  let pending = 0;
  let visible = false;
  let showTimer = null;
  let creepTimer = null;
  const reveal = () => {
    visible = true;
    bar.className = 'htmx-progress start';
    creepTimer = setTimeout(() => {
      if (pending > 0) bar.className = 'htmx-progress mid';
    }, CREEP_AFTER - SHOW_AFTER);
  };
  const start = () => {
    pending++;
    if (pending > 1) return;
    // Don't reveal yet — wait SHOW_AFTER ms. Most clicks resolve faster than
    // that on a fiber connection and never need a loading indicator.
    clearTimeout(showTimer);
    showTimer = setTimeout(reveal, SHOW_AFTER);
  };
  const finish = () => {
    pending = Math.max(0, pending - 1);
    if (pending > 0) return;
    clearTimeout(showTimer);
    clearTimeout(creepTimer);
    if (!visible) {
      // Fast request — never showed the bar, don't show it now either.
      return;
    }
    bar.className = 'htmx-progress done';
    visible = false;
    setTimeout(() => {
      if (pending === 0 && !visible) bar.className = 'htmx-progress';
    }, 280);
  };
  document.addEventListener('htmx:beforeRequest', start);
  document.addEventListener('htmx:afterRequest', finish);
  document.addEventListener('htmx:responseError', finish);
  document.addEventListener('htmx:sendError', finish);
  document.addEventListener('htmx:swapError', finish);
  document.addEventListener('htmx:timeout', finish);
})();
</script>
</body>
</html>
