<?php
// ZealAPI file: GET /api/docs/search?q=foo
// Returns an HTML fragment with inline-search suggestions for the
// /docs/api/ reference. Drives the htmx-powered search box at the top
// of every api-wrapped page. Pure HTML response — htmx swaps into
// #api-search-results, no JSON parsing on the client.

use ZealPHP\G;

$search = function () {
    $g = G::instance();
    $q = trim((string) ($g->get['q'] ?? ''));

    if ($q === '' || mb_strlen($q) < 1) {
        // Empty query → empty results. htmx swap clears the dropdown,
        // which CSS hides via :empty.
        $this->response('', 200);
        return;
    }

    // Load + cache the phpdoc search index. Parse the
    // `Search.appendIndex([...])` JS file once per worker — the format
    // is JSON wrapped in a JS function call, so we strip the wrapper
    // and decode. Cached in a static var so subsequent requests on the
    // same worker are O(filter) instead of O(parse + filter).
    static $index = null;
    if ($index === null) {
        $jsPath = dirname(__DIR__, 2) . '/public/docs/api/js/searchIndex.js';
        if (!is_file($jsPath)) {
            $this->response(
                '<div class="api-search-empty">API index not built yet — boot once with <code>php app.php</code> to generate it.</div>',
                200
            );
            return;
        }
        $raw = (string) file_get_contents($jsPath);
        $raw = (string) preg_replace('/^\s*Search\.appendIndex\s*\(\s*/', '', $raw);
        $raw = (string) preg_replace('/\s*\)\s*;?\s*$/', '', $raw);
        $decoded = json_decode($raw, true);
        $index = is_array($decoded) ? $decoded : [];
    }

    // Guide index — scan docs/*.md for H1/H2/H3 headings + the first
    // paragraph after each as the summary. Cached per worker.
    static $guides = null;
    if ($guides === null) {
        $guides  = [];
        $docsDir = dirname(__DIR__, 2) . '/docs';
        // Skip the README + the audit dumps (those aren't surfaced anyway).
        $skipSlugs = ['README', 'apache-parity-audit', 'nginx-parity-audit'];
        foreach (glob($docsDir . '/*.md') as $mdFile) {
            $slug = basename($mdFile, '.md');
            if (in_array($slug, $skipSlugs, true)) {
                continue;
            }
            $lines = file($mdFile, FILE_IGNORE_NEW_LINES);
            if (!$lines) {
                continue;
            }
            $inCode = false;
            $n      = count($lines);
            for ($i = 0; $i < $n; $i++) {
                $line = $lines[$i];
                if (preg_match('/^```/', $line)) {
                    $inCode = !$inCode;
                    continue;
                }
                if ($inCode) {
                    continue;
                }
                if (!preg_match('/^(#{1,3})\s+(.+?)\s*$/', $line, $m)) {
                    continue;
                }
                $level = strlen($m[1]);
                $title = trim($m[2]);
                $anchor = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $title));
                $anchor = trim($anchor, '-');
                // Summary: first non-empty, non-heading, non-code line.
                $summary = '';
                for ($j = $i + 1; $j < min($i + 20, $n); $j++) {
                    $next = trim((string) $lines[$j]);
                    if ($next === '' || $next[0] === '#' || str_starts_with($next, '```') || str_starts_with($next, '|') || str_starts_with($next, '---')) {
                        continue;
                    }
                    $summary = (string) preg_replace('/[*`_\[\]]+/', '', strip_tags($next));
                    if (mb_strlen($summary) > 140) {
                        $summary = mb_substr($summary, 0, 137) . '…';
                    }
                    break;
                }
                $url = '/docs/guide/' . $slug . ($level === 1 ? '' : '#' . $anchor);
                $guides[] = [
                    'fqsen'   => 'guide:' . $slug . '#' . $anchor,
                    'name'    => $title,
                    'summary' => $summary,
                    'url'     => $url,
                    '_kind'   => 'Guides',
                    '_parent' => ucwords(str_replace('-', ' ', $slug)),
                ];
            }
        }
    }

    // `G` is a runtime class_alias of RequestContext (no source declaration),
    // so phpDocumentor never indexes it — searching "G" would otherwise find
    // nothing. Prepend a synthetic entry pointing at the RequestContext page.
    // It MUST come first: "g" is a common substring, so the 100-match
    // collection ceiling below is hit long before the end of the list —
    // prepending guarantees it's collected, and its exact-name match scores
    // 0 so it sorts to the very top.
    $gAlias = [
        'fqsen'   => '\\ZealPHP\\G',
        'name'    => 'G',
        'summary' => 'Alias of RequestContext — the per-request global state container ($g, G::instance()).',
        'url'     => '/docs/api/classes/ZealPHP-RequestContext.html',
        '_kind'   => 'Classes',
        '_parent' => 'ZealPHP',
    ];

    // Merge — G alias first, then guides (ahead of API symbols when both
    // match). Final ordering still goes through the score sort.
    $combined = array_merge([$gAlias], $guides, $index);
    if (!$combined) {
        $this->response('', 200);
        return;
    }
    $index = $combined;

    // Substring case-insensitive match. Score: exact > starts-with > contains.
    $qLower = mb_strtolower($q);
    $matches = [];
    foreach ($index as $entry) {
        $name      = (string) ($entry['name'] ?? '');
        $nameLower = mb_strtolower($name);
        $pos       = mb_strpos($nameLower, $qLower);
        if ($pos === false) {
            continue;
        }
        // Score lower is better.
        $score = ($nameLower === $qLower) ? 0 : ($pos === 0 ? 1 : 2);
        $matches[] = ['score' => $score, 'len' => mb_strlen($name), 'entry' => $entry];
        if (count($matches) >= 100) {
            break; // hard ceiling
        }
    }

    if (!$matches) {
        $this->response('<div class="api-search-empty">No matches for <code>' . htmlspecialchars($q, ENT_QUOTES) . '</code>.</div>', 200);
        return;
    }

    usort($matches, static function (array $a, array $b): int {
        return ($a['score'] <=> $b['score']) ?: ($a['len'] <=> $b['len']);
    });

    // Infer kind from the FQSEN shape.
    //   \Foo\Bar              → Class
    //   \Foo\Bar::method()    → Method
    //   \Foo\Bar::$prop       → Property
    //   \Foo\Bar::CONST       → Constant
    //   \foo()                → Function
    $inferKind = static function (string $fqsen): string {
        if (str_ends_with($fqsen, '()') || str_ends_with($fqsen, '()')) {
            return str_contains($fqsen, '::') ? 'Methods' : 'Functions';
        }
        if (str_contains($fqsen, '::$')) {
            return 'Properties';
        }
        if (str_contains($fqsen, '::')) {
            return 'Constants';
        }
        return 'Classes';
    };

    // Bucket the top 12 hits by kind. Guides first — they're usually
    // the most relevant for a typed query like "lifecycle" or "routing".
    $groups = [
        'Guides'     => [],
        'Classes'    => [],
        'Methods'    => [],
        'Properties' => [],
        'Functions'  => [],
        'Constants'  => [],
    ];
    foreach (array_slice($matches, 0, 12) as $hit) {
        $entry = $hit['entry'];
        $kind  = $entry['_kind'] ?? $inferKind((string) ($entry['fqsen'] ?? ''));
        $groups[$kind][] = $entry;
    }

    // Extract the "parent" — class name for methods/properties, guide
    // title for guide entries.
    $parent = static function (array $entry): string {
        if (isset($entry['_parent'])) {
            return (string) $entry['_parent'];
        }
        $fqsen = (string) ($entry['fqsen'] ?? '');
        if (!str_contains($fqsen, '::')) {
            return '';
        }
        return ltrim(substr($fqsen, 0, strpos($fqsen, '::')), '\\');
    };

    ob_start();
    ?>
    <ul class="api-search-list">
    <?php foreach ($groups as $kind => $entries): ?>
        <?php if (!$entries) continue; ?>
        <li class="api-search-group">
            <h6 class="api-search-kind"><?= htmlspecialchars($kind, ENT_QUOTES) ?></h6>
            <?php foreach ($entries as $entry):
                $rawUrl = (string) ($entry['url'] ?? '#');
                // Guide entries already carry an absolute /docs/guide/ URL;
                // phpdoc entries are relative (classes/Foo.html#…) so prepend /docs/api/.
                $url   = str_starts_with($rawUrl, '/') ? $rawUrl : '/docs/api/' . ltrim($rawUrl, './');
                $name  = (string) ($entry['name'] ?? '');
                $sum   = (string) ($entry['summary'] ?? '');
                $par   = $parent($entry);
            ?>
                <a href="<?= htmlspecialchars($url, ENT_QUOTES) ?>"
                   class="api-search-item"
                   hx-boost="false">
                    <span class="api-search-name"><?= htmlspecialchars($name, ENT_QUOTES) ?></span>
                    <?php if ($par !== ''): ?>
                        <span class="api-search-parent"><?= htmlspecialchars($par, ENT_QUOTES) ?></span>
                    <?php endif; ?>
                    <?php if ($sum !== ''): ?>
                        <span class="api-search-summary"><?= htmlspecialchars($sum, ENT_QUOTES) ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </li>
    <?php endforeach; ?>
    </ul>
    <?php
    $html = (string) ob_get_clean();
    $this->response($html, 200);
};
