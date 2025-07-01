#!/usr/bin/env php
<?php
/**
 * generate_docblocks.php
 *
 * Scans the src/ directory and inserts stub PHPDoc comments
 * for classes and methods that lack them.
 *
 * Usage:
 *   php generate_docblocks.php
 */

$srcDir = __DIR__ . '/src';
if (!is_dir($srcDir)) {
    fwrite(STDERR, "Error: src/ directory not found\n");
    exit(1);
}

$rii = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS)
);
foreach ($rii as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $path = $file->getPathname();
    $lines = file($path);
    if ($lines === false) {
        continue;
    }
    $insertions = [];
    $modified = false;
    foreach ($lines as $i => $line) {
        // Detect class, interface, trait
        if (preg_match('/^(\s*)(abstract\s+|final\s+)?(class|interface|trait)\s+([A-Za-z_][A-Za-z0-9_]*)/', $line, $m)) {
            list(, $indent, , , $className) = $m;
            // Determine insertion point, skipping preceding comments
            $insertionIndex = $i;
            $j = $i - 1;
            $hasDoc = false;
            while ($j >= 0) {
                $trim = trim($lines[$j]);
                if ($trim === '') { $j--; continue; }
                if (strpos($trim, '/**') === 0) { $hasDoc = true; break; }
                if (strpos($trim, '//') === 0) { $insertionIndex = $j; $j--; continue; }
                if (preg_match('/^(\*|\/\*)/', $trim)) { $insertionIndex = $j; $j--; continue; }
                break;
            }
            if (!$hasDoc) {
                $doc = [];
                $doc[] = $indent . "/**\n";
                $doc[] = $indent . " * Class $className\n";
                $doc[] = $indent . " *\n";
                $doc[] = $indent . " * [TODO] Add class description.\n";
                $doc[] = $indent . " */\n";
                $insertions[$insertionIndex] = $doc;
                $modified = true;
            }
        }
        // Detect functions (methods and global)
        if (preg_match('/^(\s*)(public|protected|private)?\s*function\s+&?\s*([A-Za-z_][A-Za-z0-9_]*)\s*\(([^)]*)\)(\s*:\s*([^ {]+))?/', $line, $m)) {
            // list indices may be missing, fill defaults
            $indent = isset($m[1]) ? $m[1] : '';
            $methodName = isset($m[3]) ? $m[3] : '';
            $params = isset($m[4]) ? trim($m[4]) : '';
            $returnType = !empty($m[6]) ? trim($m[6]) : null;
            // Determine insertion point, skipping preceding comments
            $insertionIndex = $i;
            $j = $i - 1;
            $hasDoc = false;
            while ($j >= 0) {
                $trim = trim($lines[$j]);
                if ($trim === '') { $j--; continue; }
                if (strpos($trim, '/**') === 0) { $hasDoc = true; break; }
                if (strpos($trim, '//') === 0) { $insertionIndex = $j; $j--; continue; }
                if (preg_match('/^(\*|\/\*)/', $trim)) { $insertionIndex = $j; $j--; continue; }
                break;
            }
            if (!$hasDoc) {
                $doc = [];
                $doc[] = $indent . "/**\n";
                $doc[] = $indent . " * [TODO] Describe $methodName().\n";
                $doc[] = $indent . " *\n";
                if ($params !== '') {
                    $parts = preg_split('/\s*,\s*/', $params);
                    foreach ($parts as $p) {
                        if (preg_match('/(\S+\s+)?(&)?\s*(\$\w+)/', trim($p), $pm)) {
                            $paramName = $pm[3];
                        } else {
                            continue;
                        }
                        $doc[] = $indent . " * @param mixed $paramName\n";
                    }
                }
                $ret = $returnType ?: 'mixed';
                $doc[] = $indent . " * @return $ret\n";
                $doc[] = $indent . " */\n";
                $insertions[$insertionIndex] = $doc;
                $modified = true;
            }
        }
    }
    if ($modified) {
        krsort($insertions);
        foreach ($insertions as $i => $doc) {
            array_splice($lines, $i, 0, $doc);
        }
        file_put_contents($path, implode('', $lines));
        echo "Updated: $path\n";
    }
}
