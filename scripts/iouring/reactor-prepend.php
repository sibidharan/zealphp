<?php
// Loaded via auto_prepend_file in the io_uring CI job's "reactor" mode.
// reactor_type is a process-global OpenSwoole option: Server::set() rejects
// it, so it has to be set before any event loop exists, which a prepend
// guarantees for the server, phpunit, and every subprocess they spawn.
if (defined('OPENSWOOLE_IO_URING') && class_exists(\OpenSwoole\Util::class)) {
    \OpenSwoole\Util::setAio(['reactor_type' => OPENSWOOLE_IO_URING]);
}
