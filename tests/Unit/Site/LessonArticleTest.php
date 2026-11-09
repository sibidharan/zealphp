<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Site;

use PHPUnit\Framework\TestCase;
use ZealPHP\Site\LessonArticle;

/**
 * Pins the article extraction behind /api/learn/page and /api/docs/page.
 */
final class LessonArticleTest extends TestCase
{
    public function testExtractsThePlainLessonArticle(): void
    {
        $html = "<nav>side</nav>\n<article class=\"lesson-content\">\n<h1>Hi</h1>\n</article>\n<footer>f</footer>";
        $this->assertSame("<article class=\"lesson-content\">\n<h1>Hi</h1>\n</article>", LessonArticle::extract($html));
    }

    public function testExtractsAnArticleWithModifierClasses(): void
    {
        $html = '<main><article class="lesson-content docs-guide"><p>x</p></article></main>';
        $this->assertSame('<article class="lesson-content docs-guide"><p>x</p></article>', LessonArticle::extract($html));
    }

    public function testStopsAtTheFirstClosingTag(): void
    {
        $html = '<article class="lesson-content">a</article><article class="lesson-content">b</article>';
        $this->assertSame('<article class="lesson-content">a</article>', LessonArticle::extract($html));
    }

    public function testNullWithoutALessonArticle(): void
    {
        $this->assertNull(LessonArticle::extract('<article class="other">a</article>'));
        $this->assertNull(LessonArticle::extract(''));
    }
}
