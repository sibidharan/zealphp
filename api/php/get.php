<?php

use function ZealPHP\response_add_header;

$get = function() {
    response_add_header('X-Prototype',  'buffer');
    header('X-Varient: 1');
    return headers_list();
};