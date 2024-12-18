<?
use ZealPHP\App;
use ZealPHP\G;
$home = function(){
    print_r(G::get('server'));
    App::render('_master', [
        'title' => 'Zeal PHP',
        'description' => 'A simple PHP framework for Swoole',
    ]);
};