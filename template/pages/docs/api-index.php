<?php
/**
 * /docs/api/ landing — curated, grouped index of the API reference.
 *
 * Replaces phpDocumentor's flat "Table of Contents" (which buried the
 * classes behind a Packages/Namespaces/loose-functions dump) with the
 * classes grouped by namespace. $apiGroups is built by
 * ZealPHP\Docs\ApiIndex::groups() in route/docs.php.
 *
 * @var array<string, list<array{label: string, href: string}>> $apiGroups
 */
use ZealPHP\App;

$apiGroups = $apiGroups ?? [];
$classCount = array_sum(array_map('count', $apiGroups));
?>
<div class="learn-layout">
  <?php App::render('/pages/docs/_sidebar', ['topic' => '__api__']); ?>

  <article class="lesson-content docs-landing">
    <?php App::render('/pages/docs/_search'); ?>

    <nav class="docs-breadcrumbs" aria-label="Breadcrumbs">
      <a href="/docs/">Docs</a>
      <span class="sep">›</span>
      <span class="current">API Reference</span>
    </nav>

    <header class="lesson-header">
      <h1 class="lesson-title">API Reference</h1>
      <p class="lesson-subtitle">
        Auto-generated from <code>src/</code> docblocks — every public class,
        method, and property across <strong><?= (int) $classCount ?> classes</strong>.
        Grouped by namespace below; full method signatures are on each class page.
      </p>
    </header>

    <div class="api-index">
      <?php
      // Inline-swap nav (matches the guide sidebar): clicking a class swaps
      // ONLY .lesson-content — hx-select pulls it out of the class page's
      // full HTML — so the sidebar/nav/footer stay put and there's no full
      // page reload. The class page's CSS rides along inside .lesson-content.
      $apiHx = static fn (string $url): string => 'hx-get="' . htmlspecialchars($url, ENT_QUOTES) . '"'
          . ' hx-target=".lesson-content" hx-select=".lesson-content"'
          . ' hx-swap="outerHTML show:.learn-layout:top"'
          . ' hx-push-url="' . htmlspecialchars($url, ENT_QUOTES) . '"';
      ?>
      <?php foreach ($apiGroups as $group => $classes): ?>
        <section class="api-index-group">
          <h2 class="api-index-group-title"><?= htmlspecialchars($group, ENT_QUOTES) ?></h2>
          <ul class="api-index-list">
            <?php foreach ($classes as $cls): ?>
              <li>
                <a class="api-index-link" href="<?= htmlspecialchars($cls['href'], ENT_QUOTES) ?>" <?= $apiHx($cls['href']) ?>>
                  <code><?= htmlspecialchars($cls['label'], ENT_QUOTES) ?></code>
                </a>
              </li>
            <?php endforeach; ?>
          </ul>
        </section>
      <?php endforeach; ?>
    </div>
  </article>
</div>
