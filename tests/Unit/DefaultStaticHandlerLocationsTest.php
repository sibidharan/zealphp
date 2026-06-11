<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use ZealPHP\App;
use ZealPHP\Tests\TestCase;

/**
 * #367 — the default `static_handler_locations` must contain DIRECTORY entries
 * only (every one trailing-slash terminated for OpenSwoole segment-boundary
 * matching). The bare FILE entries `/favicon.ico` + `/robots.txt` were removed:
 * OpenSwoole prefix-matches them, so `/favicon.ico` stole `/favicon.icoX` and
 * `/robots.txt` stole `/robots.txt-generator` from framework routing. Those two
 * are now served as ordinary public/ files.
 */
final class DefaultStaticHandlerLocationsTest extends TestCase
{
    public function testDefaultsAreDirectoryEntriesOnly(): void
    {
        foreach (App::defaultStaticHandlerLocations() as $entry) {
            $this->assertStringEndsWith(
                '/',
                $entry,
                "default static_handler_locations entry '$entry' must be a trailing-slash directory (no bare file entries — OpenSwoole prefix-match overreaches)"
            );
        }
    }

    public function testFaviconAndRobotsAreNotBareEntries(): void
    {
        $defaults = App::defaultStaticHandlerLocations();
        // The exact #367 regression: bare file entries that over-reach.
        $this->assertNotContains('/favicon.ico', $defaults);
        $this->assertNotContains('/robots.txt', $defaults);
    }

    public function testKeepsTheSegmentBoundedDirectoryAssets(): void
    {
        $defaults = App::defaultStaticHandlerLocations();
        // The directory entries (the part that was always correct) stay.
        foreach (['/css/', '/js/', '/img/', '/assets/', '/static/'] as $dir) {
            $this->assertContains($dir, $defaults);
        }
    }

    public function testNoEntryPrefixStealsALongerSibling(): void
    {
        // Segment-boundary proof: no default entry is a string prefix of a
        // plausible longer user path (the bug class #367 belongs to).
        $defaults = App::defaultStaticHandlerLocations();
        $userPaths = ['/favicon.icoX', '/robots.txt-generator', '/json', '/cssXYZ', '/imgmap'];
        foreach ($userPaths as $path) {
            foreach ($defaults as $entry) {
                $this->assertFalse(
                    str_starts_with($path, $entry),
                    "default entry '$entry' must not prefix-steal user path '$path'"
                );
            }
        }
    }
}
