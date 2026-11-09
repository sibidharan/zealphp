<?php
// ZealAPI file: GET /api/docs/search?q=foo
// Returns an HTML fragment with inline-search suggestions for the docs —
// guides and the /docs/api/ reference. Drives the htmx-powered search box
// at the top of every docs page. Pure HTML response — htmx swaps into
// #api-search-results, no JSON parsing on the client. The search itself is
// ZealPHP\Docs\Search; the markup is template/docs/components/search_*.php.

use ZealPHP\App;
use ZealPHP\Docs\Search;
use ZealPHP\G;

$search = function () {
    $g = G::instance();
    $q = trim((string) ($g->get['q'] ?? ''));

    if ($q === '') {
        // Empty query → empty results. htmx swap clears the dropdown,
        // which CSS hides via :empty.
        $this->response('', 200);
        return;
    }

    $root = dirname(__DIR__, 2);
    $groups = Search::find($q, $root . '/public/docs/api', $root . '/docs');

    if ($groups === null) {
        $this->response((string) App::component('/docs/components/search_unbuilt'), 200);
    } elseif ($groups === []) {
        $this->response((string) App::component('/docs/components/search_no_match', ['query' => $q]), 200);
    } else {
        $this->response((string) App::component('/docs/components/search_results', ['groups' => $groups]), 200);
    }
};
