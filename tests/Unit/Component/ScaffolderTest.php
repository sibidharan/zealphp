<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Component;

use PHPUnit\Framework\TestCase;
use ZealPHP\CLI;
use ZealPHP\Component\Scaffolder;

/** `make:component`: the generated files and the command around them. */
class ScaffolderTest extends TestCase
{
    private string $root = '';
    /** @var resource */
    private $out;
    /** @var resource */
    private $err;

    /** Run the command with its output captured (writing to the real stderr kills PHPUnit on OpenSwoole 25.2). */
    private function make(string ...$args): int
    {
        return CLI::makeComponent($this->root, ['make:component', ...$args], $this->out, $this->err);
    }

    private static function read($stream): string
    {
        rewind($stream);
        return (string) stream_get_contents($stream);
    }

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/zc-scaffold-' . bin2hex(random_bytes(4));
        mkdir($this->root);
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        self::assertIsResource($out);
        self::assertIsResource($err);
        $this->out = $out;
        $this->err = $err;
        file_put_contents($this->root . '/composer.json', (string) json_encode([
            'autoload' => ['psr-4' => ['Other\\' => 'lib/', 'Acme\\Shop\\' => 'src/']],
        ]));
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f instanceof \SplFileInfo && $f->isDir() ? rmdir($f->getPathname()) : unlink((string) $f);
        }
        rmdir($this->root);
    }

    public function testNames(): void
    {
        $this->assertSame('user-card', Scaffolder::kebab('UserCard'));
        $this->assertSame('user-card', Scaffolder::kebab('user-card'));
        $this->assertSame('a2b-thing', Scaffolder::kebab('A2bThing'));
        $this->assertSame('UserCard', Scaffolder::studly('user-card'));
        $this->assertSame('UserCard', Scaffolder::studly('UserCard'));
    }

    public function testNamespaceComesFromTheSrcMapping(): void
    {
        $this->assertSame('Acme\\Shop\\', Scaffolder::namespaceFor($this->root));
        file_put_contents($this->root . '/composer.json', '{"autoload":{"psr-4":{"X\\\\":["lib/","src/"]}}}');
        $this->assertSame('X\\', Scaffolder::namespaceFor($this->root), 'a list of directories');
        file_put_contents($this->root . '/composer.json', '{"autoload":{"psr-4":{"X\\\\":"lib/"}}}');
        $this->assertSame('App\\', Scaffolder::namespaceFor($this->root), 'no mapping for src/');
        unlink($this->root . '/composer.json');
        $this->assertSame('App\\', Scaffolder::namespaceFor($this->root), 'no composer.json');
    }

    public function testReactivePlan(): void
    {
        $plan = Scaffolder::plan($this->root, 'LikeButton');
        $this->assertSame(['src/Components/LikeButton.php', 'template/components/like-button.php'], array_keys($plan['files']));
        $class = $plan['files']['src/Components/LikeButton.php'];
        $this->assertStringContainsString("namespace Acme\\Shop\\Components;", $class);
        $this->assertStringContainsString('final class LikeButton extends Component', $class);
        $this->assertStringContainsString("\$this->view('components/like-button')", $class);
        $this->assertStringContainsString("\$c->action('increment')", $plan['files']['template/components/like-button.php']);
        $this->assertStringContainsString('App::components([\\Acme\\Shop\\Components\\LikeButton::class]);', $plan['next'][0]);
        $this->assertStringContainsString('ZEALPHP_APP_KEY', $plan['next'][2]);
        $this->assertSame($plan, Scaffolder::plan($this->root, 'like-button'), 'kebab input names the same class');
    }

    public function testStatelessPlan(): void
    {
        $plan = Scaffolder::plan($this->root, 'user-card', true);
        $this->assertSame(['template/components/user-card.php'], array_keys($plan['files']));
        $this->assertStringContainsString('<div class="user-card">', $plan['files']['template/components/user-card.php']);
        $this->assertSame(["Render it: <?= App::component('user-card', ['title' => 'Hello']) ?>"], $plan['next']);
    }

    public function testBadNamesAreRefused(): void
    {
        foreach (['', 'bad name', 'user_card', '1Thing', '-x', "Card\n", '../evil'] as $name) {
            try {
                Scaffolder::plan($this->root, $name);
                $this->fail('accepted ' . json_encode($name));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testGeneratedFilesAreValidPhp(): void
    {
        foreach ([Scaffolder::plan($this->root, 'Counter'), Scaffolder::plan($this->root, 'card', true)] as $plan) {
            foreach ($plan['files'] as $path => $content) {
                try {
                    token_get_all($content, TOKEN_PARSE); // throws \ParseError on invalid PHP
                    $this->addToAssertionCount(1);
                } catch (\ParseError $e) {
                    $this->fail($path . ': ' . $e->getMessage());
                }
            }
        }
    }

    public function testCommandWritesFilesAndNeverOverwrites(): void
    {
        $this->assertSame(0, $this->make('Counter'));
        $out = self::read($this->out);
        $this->assertStringContainsString('created src/Components/Counter.php', $out);
        $this->assertStringContainsString('App::components(', $out);
        $this->assertFileExists($this->root . '/src/Components/Counter.php');
        $this->assertFileExists($this->root . '/template/components/counter.php');

        file_put_contents($this->root . '/template/components/counter.php', 'mine');
        $this->assertSame(1, $this->make('Counter'));
        $this->assertStringContainsString('already exists; nothing was written', self::read($this->err));
        $this->assertSame('mine', file_get_contents($this->root . '/template/components/counter.php'));

        $this->assertSame(0, $this->make('badge', '--stateless'));
        $this->assertFileExists($this->root . '/template/components/badge.php');
        $this->assertFileDoesNotExist($this->root . '/src/Components/Badge.php');
    }

    public function testCommandRefusesMissingAndBadNames(): void
    {
        $this->assertSame(1, $this->make());
        $this->assertSame(1, $this->make('--stateless'));
        $this->assertSame(1, $this->make('bad name'));
        $err = self::read($this->err);
        $this->assertStringContainsString('Usage: php app.php make:component', $err);
        $this->assertStringContainsString("Component name 'bad name'", $err);
        $this->assertDirectoryDoesNotExist($this->root . '/src');
    }
}
