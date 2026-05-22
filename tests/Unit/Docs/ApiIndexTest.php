<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Docs;

use PHPUnit\Framework\TestCase;
use ZealPHP\Docs\ApiIndex;

/**
 * Pins the curated API-index grouping + breadcrumb derivation.
 */
final class ApiIndexTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/apiindex-' . uniqid();
        mkdir($this->dir, 0777, true);
        foreach ([
            'ZealPHP-App',
            'ZealPHP-Store',
            'ZealPHP-Middleware-CorsMiddleware',
            'ZealPHP-Middleware-ETagMiddleware',
            'ZealPHP-HTTP-Request',
            'ZealPHP-HTTP-Factory-RequestFactory',
            'ZealPHP-Learn-Auth',
            'NotZealPHP-Thing',          // ignored (wrong prefix)
        ] as $name) {
            file_put_contents($this->dir . '/' . $name . '.html', '<html></html>');
        }
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    // ── groups() ────────────────────────────────────────────────────────

    public function testTopLevelClassesGoToCore(): void
    {
        $groups = ApiIndex::groups($this->dir);
        $labels = array_column($groups['Core'], 'label');
        $this->assertContains('App', $labels);
        $this->assertContains('Store', $labels);
    }

    public function testNamespacedClassesGroupByFirstSegment(): void
    {
        $groups = ApiIndex::groups($this->dir);
        $this->assertSame(
            ['CorsMiddleware', 'ETagMiddleware'],
            array_column($groups['Middleware'], 'label')
        );
    }

    public function testDeepNamespaceKeepsRemainderAsLabel(): void
    {
        $groups = ApiIndex::groups($this->dir);
        $labels = array_column($groups['HTTP'], 'label');
        $this->assertContains('Request', $labels);
        $this->assertContains('Factory\\RequestFactory', $labels);
    }

    public function testHrefPointsAtClassPage(): void
    {
        $groups = ApiIndex::groups($this->dir);
        $cors = null;
        foreach ($groups['Middleware'] as $entry) {
            if ($entry['label'] === 'CorsMiddleware') {
                $cors = $entry;
            }
        }
        $this->assertSame('/docs/api/classes/ZealPHP-Middleware-CorsMiddleware.html', $cors['href'] ?? null);
    }

    public function testNonZealphpFilesIgnored(): void
    {
        $groups = ApiIndex::groups($this->dir);
        $all = [];
        foreach ($groups as $items) {
            $all = array_merge($all, array_column($items, 'label'));
        }
        $this->assertNotContains('Thing', $all);
    }

    public function testCoreOrderedBeforeOtherGroups(): void
    {
        $keys = array_keys(ApiIndex::groups($this->dir));
        $this->assertSame('Core', $keys[0]);
        $this->assertContains('Learn (demo app)', $keys);     // display title mapped
        $this->assertLessThan(
            array_search('Learn (demo app)', $keys, true),
            array_search('Middleware', $keys, true)
        );
    }

    // ── breadcrumb() ────────────────────────────────────────────────────

    public function testBreadcrumbForClassPageIncludesNamespaceAndClass(): void
    {
        $crumb = ApiIndex::breadcrumb('/classes/ZealPHP-Middleware-CorsMiddleware.html');
        $labels = array_column($crumb, 'label');
        $this->assertSame(['Docs', 'API Reference', 'Middleware', 'CorsMiddleware'], $labels);
        // last segment is the current page (no link)
        $this->assertNull($crumb[array_key_last($crumb)]['href']);
    }

    public function testBreadcrumbForTopLevelClassHasNoNamespaceSegment(): void
    {
        $labels = array_column(ApiIndex::breadcrumb('/classes/ZealPHP-App.html'), 'label');
        $this->assertSame(['Docs', 'API Reference', 'App'], $labels);
    }

    public function testBreadcrumbForOtherPageType(): void
    {
        $labels = array_column(ApiIndex::breadcrumb('/namespaces/zealphp.html'), 'label');
        $this->assertSame(['Docs', 'API Reference', 'Namespaces'], $labels);
    }
}
