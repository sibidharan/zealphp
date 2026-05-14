<?php use ZealPHP\App;
App::render('/_master', [
    'title'       => 'ZealPHP · Learn',
    'page'        => 'learn',
    'active'      => 'learn',
    'description' => 'Learn ZealPHP by building a real personal-notes app with AI chat — server-rendered, PHP-native, no React tax.',
]);
