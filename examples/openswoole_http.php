<?php
$server = new OpenSwoole\HTTP\Server("0.0.0.0", 9999);

$server->on("start", function (OpenSwoole\Http\Server $server) {
    echo "OpenSwoole http server is started at http://0.0.0.0:9999\n";
});

$server->on("request", function (OpenSwoole\Http\Request $request, OpenSwoole\Http\Response $response) {
    $response->header("Content-Type", "text/plain");
    $response->end("Hello World\n");
});

$server->start();