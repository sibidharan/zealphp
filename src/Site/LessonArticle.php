<?php

declare(strict_types=1);

namespace ZealPHP\Site;

/**
 * Cuts a lesson's or docs guide's `<article class="lesson-content …">` out of a
 * full rendered page.
 *
 * The /learn and /docs sidebars navigate with htmx: `/api/learn/page` and
 * `/api/docs/page` render the page template and send back only its article,
 * so the swap target receives the article alone (no nested layout or
 * sidebar).
 */
final class LessonArticle
{
    /** The page's first `lesson-content` article, or null when it has none. */
    public static function extract(string $html): ?string
    {
        if (preg_match('/<article class="lesson-content[^"]*">.*?<\/article>/s', $html, $m) === 1) {
            return $m[0];
        }
        return null;
    }
}
