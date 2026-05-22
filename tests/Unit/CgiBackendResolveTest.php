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
}
