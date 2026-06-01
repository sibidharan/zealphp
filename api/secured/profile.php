<?php
use ZealPHP\RequestContext;

// In-file `$middleware` — co-located per-file guards, run INNERMOST (after the
// App::when('/api/secured') scope's chain). Here it stamps a request-id, which
// the handler reads back from the per-request memo.
$middleware = ['request-id'];

$profile = function () {
    return [
        'ok'         => true,
        'api'        => 'secured/profile',
        'request_id' => RequestContext::once('request_id', fn() => null),
        'note'       => 'X-Api-Secured (App::when) + X-Request-Id (in-file $middleware)',
    ];
};
