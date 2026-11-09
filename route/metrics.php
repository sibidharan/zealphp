<?php

use ZealPHP\App;

$app = App::instance();

$app->route('/metric/get/{id}', function($id) {
    return App::component('/demos/routing/received-id', ['kind' => 'api', 'id' => $id]);
});