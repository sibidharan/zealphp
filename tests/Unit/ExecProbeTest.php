<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** TEMP CI probe for the Infection exit-143 hunt — removed once found. */
class ExecProbeTest extends TestCase
{
    public function testProbeSystemExecVariants(): void
    {
        $variants = [
            ['echo hi', 'false'], ['echo hi', 'true'],
            ['exit 3', 'false'], ['exit 3', 'true'],
            ['echo e >&2', 'false'], ['echo e >&2', 'true'],
            ["echo 'DEBUG' >&2; echo '{\"ok\":true}'; exit 3", 'true'],
            ["exec 2>>/dev/null\necho 'DEBUG' >&2; echo '{\"ok\":true}'; exit 3", 'true'],
            ["exec 2>>/dev/null\necho '{\"ok\":true}'", 'true'],
            ["echo '{\"ok\":true}'", 'true'],
        ];
        $os = phpversion('openswoole');
        foreach ($variants as [$cmd, $flag]) {
            $p = proc_open([PHP_BINARY, __DIR__ . '/../probe/exec_probe.php', $cmd, $flag], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            $st = proc_close($p);
            fwrite(STDERR, sprintf("[probe] os=%s flag=%s status=%d out=%s err=%s cmd=%s\n", $os, $flag, $st, $out, trim((string) $err), json_encode($cmd)));
        }
        $this->addToAssertionCount(1);
    }
}
