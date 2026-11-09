<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * public/llms.txt and public/llms-full.txt are generated from docs/ by
 * scripts/build-agent-reference.php (`make docs-rebuild`). They ship to every
 * AI assistant that reads php.zeal.ninja/llms.txt, so they must not drift
 * from the docs.
 */
class LlmsTxtTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    public function testTheRulesMatchTheStructureDoc(): void
    {
        $llms = (string) file_get_contents(self::ROOT . '/public/llms.txt');
        $doc = (string) file_get_contents(self::ROOT . '/docs/directory-structure.md');
        $this->assertSame(1, preg_match('/^## Where code goes\n(.*?)(?=^## )/ms', $doc, $m));
        $this->assertStringContainsString('## Rules for AI assistants', $llms);
        // Every numbered rule appears as written (links rewritten to the site).
        preg_match_all('/^\d+\. \*\*[^*]+\*\*/m', $m[1], $rules);
        $this->assertNotEmpty($rules[0]);
        foreach ($rules[0] as $rule) {
            $this->assertStringContainsString($rule, $llms, 'run `make docs-rebuild`');
        }
    }

    public function testTheFullReferenceIsTheAgentReference(): void
    {
        $this->assertFileEquals(
            self::ROOT . '/examples/agents/zealphp_reference.txt',
            self::ROOT . '/public/llms-full.txt',
            'run `make docs-rebuild`'
        );
    }

    public function testEveryGuideIsLinked(): void
    {
        $llms = (string) file_get_contents(self::ROOT . '/public/llms.txt');
        foreach (['getting-started', 'directory-structure', 'components', 'templates-and-rendering'] as $slug) {
            $this->assertStringContainsString("https://php.zeal.ninja/docs/guide/{$slug})", $llms);
        }
    }
}
