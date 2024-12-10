<?
use ZealPHP\App;

$head = function(){
    App::render('/home/_master', [
        'title' => 'Zeal PHP',
        'description' => 'A simple PHP framework for Swoole',
    ]);
};