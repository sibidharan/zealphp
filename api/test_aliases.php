<?php
// Demo api endpoint proving $req / $res are accepted as short aliases for
// $request / $response in api/ handlers — they receive the same wrappers the
// long names would. Reached at GET /api/test_aliases.
$test_aliases = function ($req, $res) {
    $res->header('X-Alias-Inject', 'yes');
    return [
        'ok'             => true,
        'api'            => 'test_aliases',
        'method'         => $req->server['request_method'] ?? 'GET',
        'request_class'  => get_class($req),
        'response_class' => get_class($res),
        'echo'           => $req->get['echo'] ?? null,
        'note'           => '$req / $res are short aliases for $request / $response',
    ];
};
