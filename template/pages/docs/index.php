<?php
/**
 * /docs/ landing — narrative guides + API reference.
 *
 * Single source of truth for what's surfaced: the docs/*.md files in the repo
 * (Wave 1-3 of the docs-drift-fix sweep brought these in sync with code at
 * HEAD 46a648a, audit at .omc/research/docs-drift-audit-2026-05-22.md).
 */

$groups = [
    'Getting started' => [
        ['getting-started',      'Install PHP/OpenSwoole/uopz, boot your first server.'],
        ['directory-structure',  'Repo layout — where routes, APIs, templates, and src/ live.'],
        ['runtime-architecture', 'Request lifecycle, lifecycle setters (v0.2.27 safety throw), mode matrix.'],
    ],
    'Routing & responses' => [
        ['routing',                  'route(), nsRoute, nsPathRoute, patternRoute + parameter injection.'],
        ['api-layer',                'ZealAPI file-based REST, v0.2.25 auth hooks (authChecker, adminChecker, …).'],
        ['error-handling',           'setErrorHandler, uopz overrides, recursion guard, content-negotiated 5xx.'],
        ['templates-and-rendering',  'render / renderToString / renderStream / include / fragment — the file-execution family.'],
    ],
    'Surfaces' => [
        ['streaming',                    'yield-based SSR, stream(), sse(), renderStream — four streaming patterns.'],
        ['websocket',                    'App::ws(), per-worker fd map, frame opcodes, cross-worker broadcast via Store.'],
        ['tasks-and-concurrency',        'go(), task workers, App::tick/after, coproc(), prefork_request_handler.'],
        ['middleware-and-authentication','All 28 PSR-15 middleware classes + Apache/nginx parity citations.'],
    ],
    'Operations' => [
        ['deployment',  'systemd unit, CLI flags, PID files, Docker, OPcache tuning.'],
        ['fuzzing',     'slowhttptest, radamsa, gabbi — HTTP framing & conformance fuzzing harnesses.'],
    ],
    'Background' => [
        ['apache-parity',         'What Apache features port (and what doesn\'t) — the migration boundary.'],
        ['competitive-analysis',  'How ZealPHP positions vs FrankenPHP, RoadRunner, Octane, AMPHP.'],
        ['standards-and-roadmap', 'PSR conformance + the v0.3.0+ roadmap.'],
    ],
];

?>
<section class="docs-landing">
  <header class="docs-hero">
    <h1>ZealPHP Documentation</h1>
    <p class="lede">
      Two surfaces, one source of truth. <strong>Narrative guides</strong> walk
      through each subsystem with worked examples; the <strong>API reference</strong>
      is auto-generated from <code>src/</code> docblocks and covers every public
      method, property, and class.
    </p>
  </header>

  <div class="docs-grid">
    <article class="docs-card docs-card--api">
      <h2><a href="/docs/api/">API Reference →</a></h2>
      <p>
        Auto-generated from <code>src/</code> docblocks via phpDocumentor.
        Bundled PHAR builder lands in the next release — until then,
        <a href="/docs/api/">/docs/api/</a> shows the local-build recipe.
        Source docblocks are
        <a href="https://github.com/sibidharan/zealphp/tree/master/src" target="_blank" rel="noopener">browsable on GitHub</a>
        meanwhile.
      </p>
    </article>

    <article class="docs-card docs-card--guides">
      <h2>Guides</h2>
      <p>16 markdown-source guides covering the framework from setup to deployment.</p>
    </article>
  </div>

  <?php foreach ($groups as $heading => $topics): ?>
    <section class="docs-group">
      <h2><?= htmlspecialchars($heading, ENT_QUOTES) ?></h2>
      <ul class="docs-topics">
        <?php foreach ($topics as [$slug, $blurb]): ?>
          <li>
            <a href="/docs/guide/<?= htmlspecialchars($slug, ENT_QUOTES) ?>">
              <?= htmlspecialchars(ucwords(str_replace('-', ' ', $slug)), ENT_QUOTES) ?>
            </a>
            <span class="docs-blurb"><?= htmlspecialchars($blurb, ENT_QUOTES) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>
  <?php endforeach; ?>
</section>
