<?php
// Finding 3: reactor_type through Server::set().
$s = new OpenSwoole\Http\Server('127.0.0.1', 19502);
$ok = $s->set(['reactor_type' => OPENSWOOLE_IO_URING]);
fprintf(STDERR, "RESULT Server::set returned %s\n", var_export($ok, true));
