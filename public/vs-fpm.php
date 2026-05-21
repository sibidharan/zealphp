<?php use ZealPHP\App;
App::render('_master', [
    'title'       => 'ZealPHP · vs. PHP-FPM',
    'page'        => 'vs-fpm',
    'active'      => 'vs-fpm',
    'description' => 'How ZealPHP compares to Apache + PHP-FPM per-request: coroutine mode is dramatically faster, the legacy CGI bridge pays a comparable per-include cost. Honest cost matrix + reproducer.',
]);
