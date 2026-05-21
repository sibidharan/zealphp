<?php
/**
 * Merge per-process php-code-coverage `.cov` dumps (unit + server-worker) into
 * one report. Prints combined line %, writes clover to <dir>/clover.xml.
 *
 * Usage: php scripts/merge_coverage.php <cov-dir>
 */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Report\Clover;

$dir = $argv[1] ?? null;
if ($dir === null || !is_dir($dir)) {
    fwrite(STDERR, "usage: php scripts/merge_coverage.php <cov-dir>\n");
    exit(2);
}

$files = glob(rtrim($dir, '/') . '/*.cov') ?: [];
if ($files === []) {
    fwrite(STDERR, "no .cov files in $dir\n");
    exit(1);
}

$merged = null;
foreach ($files as $f) {
    /** @var mixed $cov */
    $cov = include $f;
    if (!$cov instanceof CodeCoverage) {
        fwrite(STDERR, "skip (not CodeCoverage): " . basename($f) . "\n");
        continue;
    }
    if ($merged === null) {
        $merged = $cov;
    } else {
        $merged->merge($cov);
    }
    fwrite(STDERR, "merged: " . basename($f) . "\n");
}

if ($merged === null) {
    fwrite(STDERR, "no usable coverage\n");
    exit(1);
}

(new Clover())->process($merged, rtrim($dir, '/') . '/clover.xml');

$xml = simplexml_load_file(rtrim($dir, '/') . '/clover.xml');
if ($xml !== false) {
    $m = $xml->project->metrics;
    $s = (int) $m['statements'];
    $c = (int) $m['coveredstatements'];
    printf("COMBINED: %d/%d = %.1f%% lines\n", $c, $s, $s > 0 ? 100 * $c / $s : 0.0);
}
