<?php

use ZealPHP\App;

$app = App::instance();

$app->route('/data/{id}', function($id) {
    return App::component('/demos/routing/received-id', ['kind' => 'API', 'id' => $id]);
});