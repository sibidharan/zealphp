<?php

use ZealPHP\App;

App::render('_master', [
    'title'  => 'Documentation · ZealPHP',
    'page'   => 'docs/index',
    'active' => 'docs',
]);
