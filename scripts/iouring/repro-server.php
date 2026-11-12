<?php
// Plain OpenSwoole HTTP server (no ZealPHP) on the io_uring reactor, :19501.
OpenSwoole\Util::setAio(['reactor_type' => OPENSWOOLE_IO_URING]);
$s = new OpenSwoole\Http\Server('127.0.0.1', 19501);
$s->set(['worker_num' => 4, 'log_level' => 0]);
$s->on('request', static function ($req, $res): void { $res->end("ok\n"); });
$s->start();
