<?php use ZealPHP\App;
App::render('_master', [
    'title'       => 'ZealPHP · Case Study — Selfmade Ninja Labs',
    'page'        => 'case-studies/sna-labs',
    'active'      => 'case-studies/sna-labs',
    'description' => 'How we made the same PHP codebase run on both Apache and ZealPHP simultaneously — 41 commits, ~2,300 lines of app code, one custom Rust extension, zero downtime.',
]);
