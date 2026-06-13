<?php
/**
 * README badge endpoints (served by php.zeal.ninja).
 *
 * GET /badge/downloads → shields.io `endpoint` JSON summing the project's
 * Packagist download counts across BOTH package names (sibidharan/zealphp +
 * zealphp/zealphp), since Packagist never merges counts across a rename.
 * The README badge points at:
 *   https://img.shields.io/endpoint?url=https://php.zeal.ninja/badge/downloads
 *
 * Logic lives in src/Badge/PackagistDownloads.php (route files stay thin and
 * function-free so dev hot-reload can re-include them).
 */

use ZealPHP\App;
use ZealPHP\Badge\PackagistDownloads;

$app = App::instance();

$app->route('/badge/downloads', function ($response) {
    // shields fetches this server-side and honours the JSON's cacheSeconds;
    // Cache-Control lets any CDN/proxy in front of php.zeal.ninja cache too.
    $response->header('Cache-Control', 'public, max-age=3600');
    return PackagistDownloads::endpoint(); // array → application/json (return contract)
}, methods: ['GET']);
