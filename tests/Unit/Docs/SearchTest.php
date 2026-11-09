<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Docs;

use PHPUnit\Framework\TestCase;
use ZealPHP\Docs\Search;

/**
 * Pins the docs search behind /api/docs/search: sources, ranking, grouping
 * and the view-ready entry shape.
 */
final class SearchTest extends TestCase
{
    private string $dir;
    private string $apiRoot;
    private string $docsDir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/docsearch-' . uniqid();
        $this->apiRoot = $this->dir . '/api';
        $this->docsDir = $this->dir . '/docs';
        mkdir($this->apiRoot . '/js', 0777, true);
        mkdir($this->docsDir, 0777, true);

        $symbols = [
            ['fqsen' => '\\ZealPHP\\Router', 'name' => 'Router', 'summary' => 'Routes requests.', 'url' => 'classes/ZealPHP-Router.html'],
            ['fqsen' => '\\ZealPHP\\App::route()', 'name' => 'route()', 'summary' => '', 'url' => './classes/ZealPHP-App.html#method_route'],
            ['fqsen' => '\\ZealPHP\\App::$routes', 'name' => '$routes', 'summary' => 'The table.', 'url' => 'classes/ZealPHP-App.html#property_routes'],
            ['fqsen' => '\\ZealPHP\\App::ROUTE_MAX', 'name' => 'ROUTE_MAX', 'summary' => '', 'url' => 'classes/ZealPHP-App.html#constant_ROUTE_MAX'],
            ['fqsen' => '\\ZealPHP\\route_to()', 'name' => 'route_to()', 'summary' => '', 'url' => 'namespaces/zealphp.html#function_route_to'],
            'not-an-entry',
        ];
        file_put_contents($this->apiRoot . '/js/searchIndex.js', "Search.appendIndex(\n" . json_encode($symbols) . "\n);\n");

        file_put_contents($this->docsDir . '/routing.md', implode("\n", [
            '# Routing guide',
            '',
            'How **routes** map to `handlers`.',
            '',
            '```php',
            '# Route in a code block',
            '```',
            '',
            '## Route groups',
            '| table | row |',
            str_repeat('long ', 40),
        ]));
        file_put_contents($this->docsDir . '/README.md', "# Route readme\n");
    }

    protected function tearDown(): void
    {
        foreach (['/api/js/searchIndex.js', '/docs/routing.md', '/docs/README.md'] as $f) {
            @unlink($this->dir . $f);
        }
        @rmdir($this->apiRoot . '/js');
        @rmdir($this->apiRoot);
        @rmdir($this->docsDir);
        @rmdir($this->dir);
    }

    public function testNullBeforeTheIndexIsBuilt(): void
    {
        unlink($this->apiRoot . '/js/searchIndex.js');
        $this->assertNull(Search::find('route', $this->apiRoot, $this->docsDir));
    }

    public function testEmptyWhenNothingMatches(): void
    {
        $this->assertSame([], Search::find('zzqq', $this->apiRoot, $this->docsDir));
    }

    public function testGroupsHitsByKindInFixedOrder(): void
    {
        $groups = Search::find('route', $this->apiRoot, $this->docsDir);
        $this->assertNotNull($groups);
        $this->assertSame(['Guides', 'Classes', 'Methods', 'Properties', 'Functions', 'Constants'], array_keys($groups));

        $this->assertSame(['Route groups'], array_column($groups['Guides'], 'name'));
        $this->assertSame(['Router'], array_column($groups['Classes'], 'name'));
        $this->assertSame(['route()'], array_column($groups['Methods'], 'name'));
        $this->assertSame(['$routes'], array_column($groups['Properties'], 'name'));
        $this->assertSame(['route_to()'], array_column($groups['Functions'], 'name'));
        $this->assertSame(['ROUTE_MAX'], array_column($groups['Constants'], 'name'));
    }

    public function testEntriesAreViewReady(): void
    {
        $groups = Search::find('route', $this->apiRoot, $this->docsDir) ?? [];

        $this->assertSame([
            'url'     => '/docs/api/classes/ZealPHP-App.html#method_route',
            'name'    => 'route()',
            'parent'  => 'ZealPHP\\App',
            'summary' => '',
        ], $groups['Methods'][0]);
        $this->assertSame('/docs/api/classes/ZealPHP-Router.html', $groups['Classes'][0]['url']);
        $this->assertSame('', $groups['Classes'][0]['parent']);
        $this->assertSame('Routes requests.', $groups['Classes'][0]['summary']);
    }

    public function testGuideHeadingsCarryAnchorsParentAndSummary(): void
    {
        $guides = (Search::find('rout', $this->apiRoot, $this->docsDir) ?? [])['Guides'];
        $byName = array_column($guides, null, 'name');

        $this->assertSame('/docs/guide/routing', $byName['Routing guide']['url']);
        $this->assertSame('How routes map to handlers.', $byName['Routing guide']['summary']);
        $this->assertSame('Routing', $byName['Routing guide']['parent']);

        $this->assertSame('/docs/guide/routing#route-groups', $byName['Route groups']['url']);
        // Tables are skipped; a long line is cut to 137 chars + an ellipsis.
        $this->assertSame(138, mb_strlen($byName['Route groups']['summary']));
        $this->assertStringEndsWith('…', $byName['Route groups']['summary']);

        // Headings inside code fences and the README are not indexed.
        $this->assertArrayNotHasKey('Route in a code block', $byName);
        $this->assertArrayNotHasKey('Route readme', $byName);
    }

    public function testExactMatchRanksFirstAndGAliasIsAlwaysFound(): void
    {
        $groups = Search::find('g', $this->apiRoot, $this->docsDir) ?? [];
        $this->assertSame('G', $groups['Classes'][0]['name']);
        $this->assertSame('/docs/api/classes/ZealPHP-RequestContext.html', $groups['Classes'][0]['url']);
        $this->assertSame('ZealPHP', $groups['Classes'][0]['parent']);
    }

    public function testMatchingIsCaseInsensitiveAndRanksPrefixBeforeContains(): void
    {
        $classes = (Search::find('ROUTER', $this->apiRoot, $this->docsDir) ?? [])['Classes'];
        $this->assertSame(['Router'], array_column($classes, 'name'));

        // 'route' is a prefix of Route groups / Router / route() / route_to() /
        // ROUTE_MAX and inside "$routes"; ties sort shortest first.
        $all = Search::find('route', $this->apiRoot, $this->docsDir) ?? [];
        $this->assertSame('Route groups', $all['Guides'][0]['name']);
    }

    public function testMalformedIndexStillSearchesGuides(): void
    {
        file_put_contents($this->apiRoot . '/js/searchIndex.js', 'Search.appendIndex(not json);');
        $groups = Search::find('routing', $this->apiRoot, $this->docsDir) ?? [];
        $this->assertSame(['Routing guide'], array_column($groups['Guides'], 'name'));
    }

    public function testKeepsAtMostTwelveHits(): void
    {
        $many = [];
        for ($i = 0; $i < 30; $i++) {
            $many[] = ['fqsen' => '\\Foo' . $i, 'name' => 'Foo' . $i, 'summary' => '', 'url' => 'classes/Foo' . $i . '.html'];
        }
        file_put_contents($this->apiRoot . '/js/searchIndex.js', 'Search.appendIndex(' . json_encode($many) . ');');
        $groups = Search::find('foo', $this->apiRoot, $this->docsDir) ?? [];
        $this->assertCount(12, $groups['Classes']);
    }
}
