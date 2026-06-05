<?php
/**
 * /docs/ landing — same learn-layout shape (sidebar + main) so the docs
 * section is visually consistent with /learn/. The main column shows a
 * brief intro and the same guide index as the sidebar, but with
 * one-line descriptions so the user can scan-pick a starting point.
 */
use ZealPHP\App;

$groups = [
    'Getting started' => [
        ['getting-started',      'Getting Started',         'Install PHP/OpenSwoole/ext-zealphp, boot your first server.'],
        ['directory-structure',  'Directory Structure',     'Repo layout — where routes, APIs, templates, and src/ live.'],
        ['runtime-architecture', 'Runtime Architecture',    'Request lifecycle, lifecycle setters (v0.2.27 safety throw), mode matrix.'],
    ],
    'Routing & responses' => [
        ['routing',                  'Routing',                  'route(), nsRoute, nsPathRoute, patternRoute + parameter injection.'],
        ['api-layer',                'API Layer',                'ZealAPI file-based REST, v0.2.25 auth hooks.'],
        ['error-handling',           'Error Handling',           'setErrorHandler, ext-zealphp overrides, recursion guard, content-negotiated 5xx.'],
        ['templates-and-rendering',  'Templates & Rendering',    'render / renderToString / renderStream / include / fragment.'],
        ['htmx',                     'HTMX',                     'isHtmx() + HtmxResponse, App::renderHtmx(), fragments, hx-boost — the full hypermedia surface.'],
    ],
    'Surfaces' => [
        ['streaming',                     'Streaming',             'yield-based SSR, stream(), sse(), renderStream.'],
        ['websocket',                     'WebSocket',             'App::ws(), per-worker fd map, frame opcodes, cross-worker broadcast.'],
        ['WSROUTER-PRODUCTION',           'WSRouter Production',   'Hardening federated WebSocket rooms — capacity, auth, backpressure, stats.'],
        ['tasks-and-concurrency',         'Tasks & Concurrency',   'go(), task workers, App::tick/after, coproc().'],
        ['middleware-and-authentication', 'Middleware & Auth',     'The full PSR-15 middleware catalog + common Apache/nginx behavior coverage.'],
    ],
    'Operations' => [
        ['deployment',            'Deployment',             'systemd unit, CLI flags, PID files, Docker, OPcache tuning.'],
        ['cli',                   'CLI Reference',          'php app.php start/stop/restart/status/logs, ports, PID files, --dev.'],
        ['hot-reload',            'Dev Hot-Reload',         'App::devReload() / ZEALPHP_DEV=1 / php app.php --dev — routes reload, no restart.'],
        ['fastcgi-backends',      'FastCGI Backends',       'Front php-fpm or any FCGI server — cgiMode(\'fcgi\') + registerCgiBackend() for custom upstreams.'],
        ['environment-variables', 'Environment Variables',  'Canonical reference for every ZEALPHP_* variable — defaults, scope, semantics.'],
        ['fuzzing',               'Fuzzing',                'slowhttptest, radamsa, gabbi — HTTP framing & conformance fuzzing.'],
        ['coroutine-isolation-security-research', 'Security Research', 'Vulnerability classes (UAF / cross-tenant leak / unbounded leak) + the ASAN & Valgrind methodology for auditing the per-coroutine isolation runtime.'],
    ],
    'Background' => [
        ['/http#parity',           'Apache Parity',          'What Apache features port — and what doesn\'t.'],
        ['compatibility-database', 'Compatibility Database', 'Per-app coroutine-legacy compatibility grades from the real-world app sweep.'],
        ['running-modern-apps',    'Running Modern Apps',    'Config recipes for Symfony / Laravel / WordPress on ZealPHP.'],
        ['competitive-analysis',   'Competitive Analysis',   'vs FrankenPHP, RoadRunner, Octane, AMPHP.'],
        ['standards-and-roadmap',  'Standards & Roadmap',    'PSR conformance + the v0.3.0+ roadmap.'],
    ],
];

// Count only real guide slugs — exclude cross-section links like '/http#parity'.
$guideCount = array_sum(array_map(
    static fn (array $items) => count(array_filter($items, static fn (array $it) => !str_starts_with($it[0], '/'))),
    $groups
));
?>
<div class="learn-layout">
  <?php App::render('/pages/docs/_sidebar', ['topic' => null]); ?>

  <article class="lesson-content docs-landing">
    <?php App::render('/pages/docs/_search'); ?>

    <header class="lesson-header">
      <nav class="lesson-crumb"><a href="/docs/">Docs</a></nav>
      <h1 class="lesson-title">ZealPHP Documentation</h1>
      <p class="lesson-subtitle">
        Two surfaces, one source of truth. <strong><?= $guideCount ?> narrative guides</strong>
        walk through each subsystem with worked examples; the
        <a href="/docs/api/">API reference</a> is auto-generated from
        <code>src/</code> docblocks and covers every public method, property,
        and class.
      </p>
    </header>

    <div class="docs-markdown">
      <?php $totalNum = 0; foreach ($groups as $heading => $items): ?>
        <h2><?= htmlspecialchars($heading, ENT_QUOTES) ?></h2>
        <ul class="docs-topics-list">
          <?php foreach ($items as [$slug, $label, $blurb]): $totalNum++; ?>
            <li>
              <?php $href = str_starts_with($slug, '/') ? $slug : "/docs/guide/{$slug}"; ?>
              <a href="<?= htmlspecialchars($href, ENT_QUOTES) ?>"><strong><?= htmlspecialchars($label, ENT_QUOTES) ?></strong></a>
              <span class="docs-topics-blurb"><?= htmlspecialchars($blurb, ENT_QUOTES) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endforeach; ?>
    </div>
  </article>
</div>
