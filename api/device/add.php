<?php
use ZealPHP\App;

${basename(__FILE__, '.php')} = function () {
    // A string: ZealAPI sends it as HTML (an object return would be JSON).
    return (string) App::component('/demos/device/add-form');
};
