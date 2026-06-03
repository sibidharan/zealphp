<?php
/**
 * build-agent-reference.php
 * =========================
 * Regenerates examples/agents/zealphp_reference.txt — the knowledge base the
 * homepage AI chat agent (examples/agents/chat_agent.py) reads through its
 * get_zealphp_reference() function tool.
 *
 * WHY THIS EXISTS
 * ---------------
 * The reference used to be hand-maintained and drifted badly out of date
 * (it had no mention of coroutine-legacy, the per-coroutine isolation stack,
 * Stage 8, etc.), so the agent couldn't answer questions about recent
 * features. This script rebuilds it deterministically from the canonical
 * user-facing docs in docs/*.md, so `make docs-rebuild` keeps the agent's
 * knowledge in lockstep with the documentation.
 *
 * OUTPUT SHAPE
 * ------------
 * chat_agent.py splits the reference on "\n## " and substring-matches the
 * query against each section's heading + body, returning the top 3 matches.
 * So granularity matters: each source doc's own H2 headings become the
 * splittable "## " sections (kept small + precise), and every section heading
 * is prefixed with its doc title ("## Routing — File-based routing") so doc
 * context is preserved and a query can match either the doc topic or the
 * subtopic. Headings deeper than H2 (### and below) are left intact — they
 * don't start with "## " so they never split. The first block starts with
 * "ZealPHP" to match the agent's intro special-case.
 *
 *   php scripts/build-agent-reference.php          # rebuild from docs/*.md
 *   make docs-rebuild                              # rebuild API + agent ref
 *
 * Deterministic: no timestamps, stable ordering — re-running with unchanged
 * docs produces a byte-identical file (clean git diffs).
 */

declare(strict_types=1);

$root   = dirname(__DIR__);
$docsDir = $root . '/docs';
$outPath = $root . '/examples/agents/zealphp_reference.txt';

// Docs that aren't useful as agent knowledge: the changelog dump, the docs
// index, and the deep internal architecture/* dives (too verbose + internal).
// Everything else under docs/*.md is included automatically, so a new doc
// page is picked up on the next rebuild with no edit here.
$exclude = [
    'README.md',                  // docs index / table of contents
    'CHANGES-SINCE-v0.2.38.md',   // changelog, not reference material
];

// Preferred ordering — a reader-friendly progression. Any doc not listed
// here is appended afterwards in alphabetical order (so new docs still land
// in the file, just at the end).
$order = [
    'getting-started.md',
    'directory-structure.md',
    'routing.md',
    'api-layer.md',
    'error-handling.md',
    'templates-and-rendering.md',
    'streaming.md',
    'websocket.md',
    'middleware-and-authentication.md',
    'sessions.md',
    'tasks-and-concurrency.md',
    'running-modern-apps.md',
    'compatibility-database.md',
    'runtime-architecture.md',
    'fastcgi-backends.md',
    'apache-parity.md',
    'hot-reload.md',
    'environment-variables.md',
    'cli.md',
    'deployment.md',
];

$all = glob($docsDir . '/*.md') ?: [];
$files = [];
foreach ($all as $path) {
    $base = basename($path);
    if (in_array($base, $exclude, true)) {
        continue;
    }
    $files[$base] = $path;
}

// Sort: known order first (by $order index), then unknown docs alphabetically.
uksort($files, function (string $a, string $b) use ($order): int {
    $ia = array_search($a, $order, true);
    $ib = array_search($b, $order, true);
    if ($ia === false && $ib === false) {
        return strcmp($a, $b);
    }
    if ($ia === false) {
        return 1;   // a unknown → after b
    }
    if ($ib === false) {
        return -1;  // b unknown → after a
    }
    return $ia <=> $ib;
});

/**
 * Turn a doc filename ("running-modern-apps.md") into a Title Case heading
 * ("Running Modern Apps") for the fallback when a doc has no leading H1.
 */
function humanizeFilename(string $base): string
{
    $name = preg_replace('/\.md$/', '', $base) ?? $base;
    $name = str_replace('-', ' ', $name);
    return ucwords($name);
}

/**
 * Normalize one markdown doc into doc-title-prefixed "## " sections:
 *  - the doc title is its first "# H1" (else the humanized filename),
 *  - the first H1 becomes a "## <DocTitle>" section (the doc intro),
 *  - every other H1/H2 becomes "## <DocTitle> — <Heading>" so each section
 *    carries its doc context and the agent's "\n## " splitter keeps them
 *    small + individually matchable,
 *  - H3+ headings are left untouched (they don't split).
 */
function normalizeDoc(string $base, string $raw): string
{
    $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];

    // Doc title = first H1 if present, else humanized filename.
    $docTitle = humanizeFilename($base);
    foreach ($lines as $line) {
        if (preg_match('/^#\s+(.+?)\s*$/', $line, $m)) {
            $docTitle = trim($m[1]);
            break;
        }
    }

    $out = [];
    $sawDocTitle = false;
    foreach ($lines as $line) {
        // H1 or H2 → promote to a doc-prefixed "## " section heading.
        if (preg_match('/^#{1,2}\s+(.+?)\s*$/', $line, $m)) {
            $heading = trim($m[1]);
            if (!$sawDocTitle && $heading === $docTitle) {
                $out[] = '## ' . $docTitle;     // the doc intro section
                $sawDocTitle = true;
            } else {
                $out[] = '## ' . $docTitle . ' — ' . $heading;
            }
            continue;
        }
        $out[] = $line;
    }

    // If the doc never opened with its own title heading, prepend one so its
    // intro prose isn't orphaned into the previous doc's last section.
    if (!$sawDocTitle) {
        array_unshift($out, '## ' . $docTitle);
    }

    // Collapse 3+ blank lines to a single blank line, trim edges.
    $body = implode("\n", $out);
    $body = preg_replace("/\n{3,}/", "\n\n", $body) ?? $body;
    return trim($body);
}

$header = "ZealPHP Framework Reference\n"
    . "Auto-generated by scripts/build-agent-reference.php from docs/*.md.\n"
    . "DO NOT EDIT BY HAND — run `make docs-rebuild` (or `php scripts/build-agent-reference.php`) to regenerate.\n"
    . "This file is the knowledge base for the homepage AI chat agent (examples/agents/chat_agent.py).";

$blocks = [$header];
$docCount = 0;
foreach ($files as $base => $path) {
    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        continue;
    }
    $body = normalizeDoc($base, $raw);
    if ($body === '') {
        continue;
    }
    $blocks[] = $body;
    $docCount++;
}

$out = implode("\n\n", $blocks) . "\n";

if (file_put_contents($outPath, $out) === false) {
    fwrite(STDERR, "[build-agent-reference] failed to write {$outPath}\n");
    exit(1);
}

$sections = substr_count($out, "\n## ");
$kb = round(strlen($out) / 1024, 1);
fwrite(
    STDOUT,
    "[build-agent-reference] wrote {$sections} sections from {$docCount} docs "
    . "({$kb} KB) → examples/agents/zealphp_reference.txt\n"
);
