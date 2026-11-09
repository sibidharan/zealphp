<?php
// TEMP CI probe (exit-143 hunt): run one System::exec variant in this
// throwaway process, so a process-group kill takes down only this child.
require __DIR__ . '/../../vendor/autoload.php';
[$_, $cmd, $flag] = $argv;
OpenSwoole\Coroutine::run(function () use ($cmd, $flag) {
    $r = OpenSwoole\Coroutine\System::exec($cmd, $flag === 'true');
    fwrite(STDOUT, json_encode($r));
});
