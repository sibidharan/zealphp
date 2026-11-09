<?php

declare(strict_types=1);

namespace ZealPHP\Docs;

/**
 * The docs search behind the box atop every /docs/api/ page
 * (`GET /api/docs/search?q=`).
 *
 * Searches three sources: the phpDocumentor symbol index
 * (`public/docs/api/js/searchIndex.js`), the H1–H3 headings of the guides in
 * `docs/*.md`, and a synthetic entry for `G` (a runtime class_alias of
 * RequestContext, which phpDocumentor never indexes). Matching is a
 * case-insensitive substring on the name, ranked exact > starts-with >
 * contains, then by name length; the top 12 hits come back grouped by kind
 * for template/docs/components/search_results.php. Both indexes are read on
 * every call, so a rebuilt reference or an edited guide shows up at once.
 */
final class Search
{
    /** Guides first: they're usually what a typed query like "lifecycle" wants. */
    private const KINDS = ['Guides', 'Classes', 'Methods', 'Properties', 'Functions', 'Constants'];

    /** Guide files that aren't surfaced (the README and the audit dumps). */
    private const SKIP_GUIDES = ['README', 'apache-parity-audit', 'nginx-parity-audit'];

    /**
     * Matches for `$query`, grouped by kind (Guides, Classes, Methods,
     * Properties, Functions, Constants — empty kinds included), each entry
     * view-ready: an absolute `url`, the `name`, the `parent` (class or guide
     * title, '' when none) and the `summary`.
     *
     * Returns [] when nothing matches, null when the phpDocumentor index
     * isn't built yet.
     *
     * @return array<string, list<array{url: string, name: string, parent: string, summary: string}>>|null
     */
    public static function find(string $query, string $apiRoot, string $docsDir): ?array
    {
        $symbols = self::symbolIndex($apiRoot . '/js/searchIndex.js');
        if ($symbols === null) {
            return null;
        }

        // G alias first, then guides (ahead of API symbols when both match).
        // It MUST come first: "g" is a common substring, so the 100-match
        // ceiling below is hit long before the end of the list — prepending
        // guarantees it's collected, and its exact-name match scores 0 so it
        // sorts to the very top.
        $entries = array_merge([self::gAlias()], self::guideIndex($docsDir), $symbols);

        $qLower = mb_strtolower($query);
        $matches = [];
        foreach ($entries as $entry) {
            $name      = self::field($entry, 'name');
            $nameLower = mb_strtolower($name);
            $pos       = mb_strpos($nameLower, $qLower);
            if ($pos === false) {
                continue;
            }
            // Lower is better.
            $score = ($nameLower === $qLower) ? 0 : ($pos === 0 ? 1 : 2);
            $matches[] = ['score' => $score, 'len' => mb_strlen($name), 'entry' => $entry];
            if (count($matches) >= 100) {
                break; // hard ceiling
            }
        }

        if ($matches === []) {
            return [];
        }

        usort($matches, static function (array $a, array $b): int {
            return ($a['score'] <=> $b['score']) ?: ($a['len'] <=> $b['len']);
        });

        $groups = array_fill_keys(self::KINDS, []);
        foreach (array_slice($matches, 0, 12) as $hit) {
            $entry = $hit['entry'];
            $kind  = isset($entry['_kind']) ? self::field($entry, '_kind') : self::kindOf(self::field($entry, 'fqsen'));
            $rawUrl = isset($entry['url']) ? self::field($entry, 'url') : '#';
            $groups[$kind][] = [
                // Guide entries carry an absolute /docs/guide/ URL; phpdoc
                // entries are relative (classes/Foo.html#…).
                'url'     => str_starts_with($rawUrl, '/') ? $rawUrl : '/docs/api/' . ltrim($rawUrl, './'),
                'name'    => self::field($entry, 'name'),
                'parent'  => self::parentOf($entry),
                'summary' => self::field($entry, 'summary'),
            ];
        }
        return $groups;
    }

    /**
     * The phpDocumentor index: `Search.appendIndex([...])`, JSON wrapped in a
     * JS call. Null when the file doesn't exist.
     *
     * @return list<array<mixed>>|null
     */
    private static function symbolIndex(string $jsPath): ?array
    {
        if (!is_file($jsPath)) {
            return null;
        }
        $raw = (string) file_get_contents($jsPath);
        $raw = (string) preg_replace('/^\s*Search\.appendIndex\s*\(\s*/', '', $raw);
        $raw = (string) preg_replace('/\s*\)\s*;?\s*$/', '', $raw);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        return array_values(array_filter($decoded, 'is_array'));
    }

    /**
     * Every H1/H2/H3 of the guides, with the first paragraph after it as the
     * summary.
     *
     * @return list<array<string, string>>
     */
    private static function guideIndex(string $docsDir): array
    {
        $guides = [];
        foreach (glob($docsDir . '/*.md') ?: [] as $mdFile) {
            $slug = basename($mdFile, '.md');
            if (in_array($slug, self::SKIP_GUIDES, true)) {
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
                    $next = trim($lines[$j]);
                    if ($next === '' || $next[0] === '#' || str_starts_with($next, '```') || str_starts_with($next, '|') || str_starts_with($next, '---')) {
                        continue;
                    }
                    $summary = (string) preg_replace('/[*`_\[\]]+/', '', strip_tags($next));
                    if (mb_strlen($summary) > 140) {
                        $summary = mb_substr($summary, 0, 137) . '…';
                    }
                    break;
                }
                $guides[] = [
                    'fqsen'   => 'guide:' . $slug . '#' . $anchor,
                    'name'    => $title,
                    'summary' => $summary,
                    'url'     => '/docs/guide/' . $slug . ($level === 1 ? '' : '#' . $anchor),
                    '_kind'   => 'Guides',
                    '_parent' => ucwords(str_replace('-', ' ', $slug)),
                ];
            }
        }
        return $guides;
    }

    /** @return array<string, string> */
    private static function gAlias(): array
    {
        return [
            'fqsen'   => '\\ZealPHP\\G',
            'name'    => 'G',
            'summary' => 'Alias of RequestContext — the per-request global state container ($g, G::instance()).',
            'url'     => '/docs/api/classes/ZealPHP-RequestContext.html',
            '_kind'   => 'Classes',
            '_parent' => 'ZealPHP',
        ];
    }

    /**
     * The kind, from the FQSEN's shape:
     *   \Foo\Bar → Classes, \Foo\Bar::method() → Methods, \Foo\Bar::$prop →
     *   Properties, \Foo\Bar::CONST → Constants, \foo() → Functions.
     */
    private static function kindOf(string $fqsen): string
    {
        if (str_ends_with($fqsen, '()')) {
            return str_contains($fqsen, '::') ? 'Methods' : 'Functions';
        }
        if (str_contains($fqsen, '::$')) {
            return 'Properties';
        }
        if (str_contains($fqsen, '::')) {
            return 'Constants';
        }
        return 'Classes';
    }

    /**
     * The class name for methods/properties/constants, the guide title for
     * guide entries, '' otherwise.
     *
     * @param array<mixed> $entry
     */
    private static function parentOf(array $entry): string
    {
        if (isset($entry['_parent'])) {
            return self::field($entry, '_parent');
        }
        $fqsen = self::field($entry, 'fqsen');
        $sep = strpos($fqsen, '::');
        if ($sep === false) {
            return '';
        }
        return ltrim(substr($fqsen, 0, $sep), '\\');
    }

    /** @param array<mixed> $entry */
    private static function field(array $entry, string $key): string
    {
        $v = $entry[$key] ?? '';
        return is_scalar($v) ? (string) $v : '';
    }
}
