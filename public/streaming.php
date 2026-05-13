<?php use ZealPHP\App;
App::render('_master', ['title' => 'ZealPHP · Streaming', 'page' => 'streaming', 'active' => 'streaming',
    'description' => 'SSR streaming via Generator yield, stream() callback, and Server-Sent Events.']);
