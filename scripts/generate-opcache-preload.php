<?php

declare(strict_types=1);

/**
 * Generate an opcache.preload script for any PHP app, so legacy apps with
 * top-level function/class declarations run cleanly in ZealPHP's pure
 * coroutine mode (no re-declare crash on warm workers).
 *
 * Usage:
 *   php generate-opcache-preload.php <app-root> > preload.php
 *   # then in php.ini:
 *   #   opcache.preload=/absolute/path/to/preload.php
 *   #   opcache.preload_user=www-data
 *
 * How it works:
 *   - Walks the app's PHP files
 *   - Uses PHP's tokenizer to detect top-level `function foo() {}` and
 *     `class Bar` declarations
 *   - Emits a preload script that calls opcache_compile_file() on each
 *     such file. opcache.preload runs at SAPI startup; the compiled
 *     functions/classes are baked into the process-wide tables.
 *   - On the warm path, request handlers find the symbols already
 *     bound; no E_COMPILE_ERROR "Cannot redeclare", no Stage 4 swap
 *     needed, no Stage 5 / engine-patch required.
 *
 * Why this works for everything:
 *   - WordPress wp-login.php → preloaded → login_header()/etc. bound at
 *     startup → request 2 finds them, doesn't re-declare.
 *   - Drupal's admin pages, MediaWiki's setup, Cacti's includes — same.
 *   - Apps NOT in the preload list (autoload-only frameworks like
 *     Symfony/Laravel) work without it — Stage 4 already covers them.
 *
 * What this is NOT:
 *   - Not a ZealPHP-specific mechanism. opcache.preload is a stock
 *     PHP 7.4+ feature. ZealPHP just leverages it.
 *   - Not fully automatic for every app. Apps with INTENTIONAL
 *     duplicate symbols (WordPress: noop.php + formatting.php both
 *     declare esc_attr(); only one loaded per request based on
 *     context) crash preload with "Cannot redeclare". The exclude
 *     list below has the known WordPress cases — extend per app.
 *   - Not safe for apps with complex inheritance graphs that span
 *     plugins (Akismet extends WP_CLI_Command, third-party PSR-7
 *     classes extend interfaces from other vendored libs).
 *     opcache.preload requires ALL parents to be preloaded first
 *     or it emits "Can't preload unlinked class" warnings and the
 *     class doesn't get cached. WordPress's plugin/theme structure
 *     makes the dependency graph un-traversable without runtime
 *     execution. FrankenPHP's WordPress mode hand-curates the preload
 *     list to work around this. ZealPHP's automated scan-and-emit
 *     approach works cleanly for simpler apps (Drupal core, simpler
 *     CMSs, custom apps) but needs per-app tuning for the larger
 *     plugin-heavy systems.
 *
 * For apps where preload doesn't work cleanly, the documented
 * fallback is M1 Pool routing via App::registerCgiBackend for the
 * specific endpoints that need fresh-process semantics. See
 * docs/compatibility-database.md for the FPM pair-up guidance.
 */

$root = $argv[1] ?? null;
if (!$root || !is_dir($root)) {
    fwrite(STDERR, "Usage: php {$argv[0]} <app-root>\n");
    fwrite(STDERR, "  Emits a preload.php to stdout that calls\n");
    fwrite(STDERR, "  opcache_compile_file() for every file in <app-root>\n");
    fwrite(STDERR, "  that has top-level function/class declarations.\n");
    exit(1);
}

$root = realpath($root);
$exclude = [
    'node_modules', '.git', 'vendor/symfony/console/Tests', 'tests', 'test',
    // Apps with intentional duplicate symbols across files (NOT redeclare
    // bugs — design pattern where the SAME function is defined in multiple
    // places, only one loaded per request based on context). Preloading
    // both crashes with "Cannot redeclare". Filter known cases:
    'wp-admin/includes/noop.php',      // WP no-op stubs for the front-end
    'wp-includes/compat.php',          // WP fallback impls for missing exts
    'wp-includes/wp-db.php',           // legacy alias to wp-includes/class-wpdb.php
];

/**
 * Returns true when the file has at least one top-level `function name()`
 * or `class Name` declaration. Uses PHP's tokenizer — doesn't execute.
 */
function hasTopLevelDecl(string $file): bool
{
    $src = @file_get_contents($file);
    if ($src === false) {
        return false;
    }
    $tokens = @token_get_all($src);
    if (!$tokens) {
        return false;
    }
    $brace = 0;
    foreach ($tokens as $tok) {
        if (is_string($tok)) {
            if ($tok === '{') {
                $brace++;
            } elseif ($tok === '}') {
                $brace--;
            }
            continue;
        }
        [$id, $text] = $tok;
        if ($brace === 0
            && in_array($id, [T_FUNCTION, T_CLASS, T_INTERFACE, T_TRAIT], true)) {
            return true;
        }
    }
    return false;
}

$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (!$f->isFile() || $f->getExtension() !== 'php') {
        continue;
    }
    $path = $f->getPathname();
    foreach ($exclude as $ex) {
        // Directory exclusion (e.g., 'node_modules') OR specific file
        // (e.g., 'wp-admin/includes/noop.php').
        if (str_contains($path, '/' . $ex . '/') || str_ends_with($path, '/' . $ex)) {
            continue 2;
        }
    }
    if (hasTopLevelDecl($path)) {
        $files[] = $path;
    }
}

sort($files);

// Emit the preload script
echo "<?php\n";
echo "// Auto-generated by ZealPHP scripts/generate-opcache-preload.php\n";
echo "// Source: {$root}\n";
echo "// Files with top-level fn/class decls: " . count($files) . "\n";
echo "// Generated: " . date('Y-m-d H:i:s') . "\n\n";

echo "// opcache.preload requires opcache.preload_user when running as root.\n";
echo "// Set in php.ini:\n";
echo "//   opcache.preload={$argv[0]}.preload.php  (this output)\n";
echo "//   opcache.preload_user=www-data\n\n";

echo "// Files compiled at SAPI startup so their top-level fn/class decls\n";
echo "// are bound into the process function/class tables once. Subsequent\n";
echo "// request includes find them already-bound, avoiding 'Cannot redeclare'\n";
echo "// crashes in long-running coroutine workers.\n\n";

foreach ($files as $f) {
    echo "opcache_compile_file('" . addslashes($f) . "');\n";
}

fwrite(STDERR, "Generated preload for " . count($files) . " files\n");
