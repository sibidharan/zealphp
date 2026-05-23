<?php
declare(strict_types=1);
namespace ZealPHP\Tests\Unit;
use PHPUnit\Framework\TestCase;
use ZealPHP\App;

final class CgiBackendResolveTest extends TestCase
{
    /** @var array<string, array{mode:string, interpreter?:string|null, address?:string, fcgi_params?:array<string,string>, exec_paths?:array<int,string>}> */
    private array $savedBackends = [];
    /** @var array<string, array{mode:string, interpreter?:string|null, address?:string, fcgi_params?:array<string,string>}> */
    private array $savedAliases = [];

    protected function setUp(): void
    {
        $this->savedBackends = App::$cgi_backends;
        $this->savedAliases  = App::$cgi_script_aliases;
        App::resetCgiBackends();
    }

    protected function tearDown(): void
    {
        App::$cgi_backends       = $this->savedBackends;
        App::$cgi_script_aliases = $this->savedAliases;
    }

    public function testExtensionInsideExecPathMayExecute(): void
    {
        App::registerCgiBackend('.py', ['mode' => 'proc', 'interpreter' => '/usr/bin/python3', 'exec_paths' => ['/cgi-bin']]);
        $r = App::resolveCgiBackend('/abs/public/cgi-bin/x.py', '/cgi-bin/x.py');
        $this->assertTrue($r['mayExecute']);
        $this->assertSame('/usr/bin/python3', $r['backend']['interpreter']);
    }
    public function testExtensionOutsideExecPathMayNotExecute(): void
    {
        App::registerCgiBackend('.py', ['mode' => 'proc', 'interpreter' => '/usr/bin/python3', 'exec_paths' => ['/cgi-bin']]);
        $r = App::resolveCgiBackend('/abs/public/uploads/x.py', '/uploads/x.py');
        $this->assertFalse($r['mayExecute']);
    }
    public function testScriptAliasMakesAnyFileExecutable(): void
    {
        App::cgiScriptAlias('/cgi-bin', ['mode' => 'proc']);
        $r = App::resolveCgiBackend('/abs/public/cgi-bin/x.sh', '/cgi-bin/x.sh');
        $this->assertTrue($r['mayExecute']);
    }
    public function testUnregisteredFallsBackNoExecute(): void
    {
        $r = App::resolveCgiBackend('/abs/public/x.py', '/x.py');
        $this->assertFalse($r['mayExecute']);
    }
    public function testExecPathBoundaryNotPrefixMatch(): void
    {
        App::registerCgiBackend('.py', ['mode' => 'proc', 'interpreter' => '/usr/bin/python3', 'exec_paths' => ['/cgi-bin']]);
        // "/cgi-bins/x.py" must NOT match the "/cgi-bin" scope
        $r = App::resolveCgiBackend('/abs/public/cgi-bins/x.py', '/cgi-bins/x.py');
        $this->assertFalse($r['mayExecute']);
    }

    public function testScriptAliasStoresFullProcConfig(): void
    {
        App::cgiScriptAlias('/cgi-bin/', [
            'mode'        => 'proc',
            'interpreter' => '/usr/bin/python3',
        ]);
        // Trailing slash on the prefix is normalized to a leading-slash, no-trailing key.
        $this->assertArrayHasKey('/cgi-bin', App::$cgi_script_aliases);
        $entry = App::$cgi_script_aliases['/cgi-bin'];
        $this->assertSame('proc', $entry['mode']);
        $this->assertSame('/usr/bin/python3', $entry['interpreter']);
    }

    public function testScriptAliasStoresFcgiAddressAndParams(): void
    {
        App::cgiScriptAlias('/fcgi', [
            'mode'        => 'fcgi',
            'address'     => '127.0.0.1:9001',
            'fcgi_params' => ['SCRIPT_FILENAME' => '/x'],
        ]);
        $entry = App::$cgi_script_aliases['/fcgi'];
        $this->assertSame('fcgi', $entry['mode']);
        $this->assertSame('127.0.0.1:9001', $entry['address']);
        $this->assertSame(['SCRIPT_FILENAME' => '/x'], $entry['fcgi_params']);
        $this->assertArrayNotHasKey('interpreter', $entry);
    }

    public function testScriptAliasDefaultsModeToProc(): void
    {
        App::cgiScriptAlias('/bin', []);
        $this->assertSame('proc', App::$cgi_script_aliases['/bin']['mode']);
    }

    /**
     * Apache parity: when a registered extension AND a ScriptAlias both
     * cover the same URL, the per-extension config wins — it's the source of
     * truth for the interpreter. The alias still supplies the ExecCGI scope.
     * (Regression guard: previously the alias shadowed per-ext, causing
     *  Python/Perl scripts to fall through to the PHP cgi_worker.)
     */
    public function testPerExtensionInterpreterWinsOverAliasWhenBothMatch(): void
    {
        App::registerCgiBackend('.py', ['mode' => 'proc', 'interpreter' => '/usr/bin/python3', 'exec_paths' => ['/cgi-bin']]);
        App::cgiScriptAlias('/cgi-bin', ['mode' => 'proc']);
        $r = App::resolveCgiBackend('/abs/public/cgi-bin/x.py', '/cgi-bin/x.py');
        $this->assertTrue($r['mayExecute']);
        $this->assertSame('/usr/bin/python3', $r['backend']['interpreter'] ?? null);
    }

    /**
     * Aliases broaden ExecCGI scope: when a per-extension entry exists but
     * the URL is outside its `exec_paths`, an overlapping alias should still
     * permit execution (and use the per-extension interpreter).
     */
    public function testAliasBroadensExecScopeOfPerExtensionInterpreter(): void
    {
        App::registerCgiBackend('.py', ['mode' => 'proc', 'interpreter' => '/usr/bin/python3', 'exec_paths' => ['/cgi-bin']]);
        App::cgiScriptAlias('/srv-cgi', ['mode' => 'proc']);
        $r = App::resolveCgiBackend('/abs/public/srv-cgi/x.py', '/srv-cgi/x.py');
        $this->assertTrue($r['mayExecute'], 'alias supplies scope');
        $this->assertSame('/usr/bin/python3', $r['backend']['interpreter'] ?? null, 'per-ext interpreter still wins');
    }

    /**
     * When no per-extension backend matches but a ScriptAlias does, the
     * alias config is returned with `mayExecute = true`. The downstream
     * dispatch in cgiSubprocess shebang-execs the file (interpreter absent).
     */
    public function testAliasOnlyReturnsAliasBackendForUnregisteredExtension(): void
    {
        App::cgiScriptAlias('/cgi-bin', ['mode' => 'proc']);
        $r = App::resolveCgiBackend('/abs/public/cgi-bin/hello.sh', '/cgi-bin/hello.sh');
        $this->assertTrue($r['mayExecute']);
        $this->assertArrayNotHasKey('interpreter', $r['backend']);
    }
}
