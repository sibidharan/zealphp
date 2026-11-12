<?php
// Finding 5: minimal HTTP server on the io_uring reactor, port 19501.
// php server.php [process|base] [setAio|coroutineSet|none]
$mode = ($argv[1] ?? 'process') === 'base' ? OpenSwoole\Server::SIMPLE_MODE : OpenSwoole\Server::POOL_MODE;
$how = $argv[2] ?? 'setAio';
if ($how === 'setAio') {
    OpenSwoole\Util::setAio(['reactor_type' => OPENSWOOLE_IO_URING]);
} elseif ($how === 'coroutineSet') {
    OpenSwoole\Coroutine::set(['reactor_type' => OPENSWOOLE_IO_URING]);
}
$s = new OpenSwoole\Http\Server('127.0.0.1', 19501, $mode);
$s->set(['worker_num' => 2, 'log_level' => 0]);
$s->on('request', static function ($req, $res): void { $res->end("ok\n"); });
$s->start();
