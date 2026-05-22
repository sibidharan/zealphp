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

    if (!$index) {
        $this->response('', 200);
        return;
    }

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

    // Bucket the top 12 hits by kind.
    $groups = [
        'Classes'    => [],
        'Methods'    => [],
        'Properties' => [],
        'Functions'  => [],
        'Constants'  => [],
    ];
    foreach (array_slice($matches, 0, 12) as $hit) {
        $entry = $hit['entry'];
        $kind  = $inferKind((string) ($entry['fqsen'] ?? ''));
        $groups[$kind][] = $entry;
    }

    // Extract the "parent" — the class name for methods/properties.
    $parent = static function (array $entry): string {
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
                $url   = '/docs/api/' . ltrim((string) ($entry['url'] ?? '#'), './');
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
