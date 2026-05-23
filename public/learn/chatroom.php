<?php use ZealPHP\App;
$user = \ZealPHP\Learn\Auth::currentUser();
App::render('_master', [
    'title'  => 'ZealPHP · Multi-Room Group Chat',
    'page'   => 'learn/chatroom',
    'active' => 'learn/chatroom',
    'user'   => $user,
]);
