<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Component;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ZealPHP\App;
use ZealPHP\AppKey;
use ZealPHP\Component\Action;
use ZealPHP\Component\Component;
use ZealPHP\Component\Model;
use ZealPHP\Component\Registry;
use ZealPHP\Tests\Unit\Component\Fixtures\Counter;
use ZealPHP\Tests\Unit\Component\Fixtures\Guarded;
use ZealPHP\Tests\Unit\Component\Fixtures\Signup;

class RegistryTest extends TestCase
{
    protected function tearDown(): void
    {
        Registry::reset();
        AppKey::reset();
        App::devReload(false);
    }

    public function testDefaultNamesAreKebabCase(): void
    {
        $this->assertSame('todo-list', Registry::defaultName('App\\Components\\TodoList'));
        $this->assertSame('counter', Registry::defaultName('Counter'));
        $this->assertSame('html-editor', Registry::defaultName('A\\HtmlEditor'));
    }

    public function testRegisterAndLookUp(): void
    {
        $this->assertTrue(Registry::isEmpty());
        App::components([Counter::class, 'sign-up' => Signup::class]);
        $this->assertFalse(Registry::isEmpty());
        $this->assertSame(Counter::class, Registry::byName('counter')?->class);
        $this->assertSame('sign-up', Registry::byClass(Signup::class)?->name);
        $this->assertSame(['counter' => Counter::class, 'sign-up' => Signup::class], Registry::all());
        App::components([Counter::class]); // idempotent
        $this->assertCount(2, Registry::all());
    }

    public function testNameClashesAreRefused(): void
    {
        Registry::register(Counter::class, 'thing');
        try {
            Registry::register(Guarded::class, 'thing');
            $this->fail('second class under the same name accepted');
        } catch (\LogicException) {
        }
        $this->expectException(\LogicException::class);
        Registry::register(Counter::class, 'other');
    }

    public function testInvalidNamesAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Registry::register(Counter::class, 'Bad_Name');
    }

    public function testNamesWithATrailingNewlineAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Registry::register(Counter::class, "counter\n");
    }

    public function testMetaDescribesStateModelsAndActions(): void
    {
        $meta = Registry::register(Signup::class);
        $this->assertArrayHasKey('saved', $meta->props);
        $this->assertArrayNotHasKey('saved', $meta->models);
        $this->assertTrue($meta->models['username']->live);
        $this->assertSame(['save', 'leave'], array_keys($meta->actions));
        $this->assertArrayNotHasKey('tag', $meta->props, 'protected config is not state');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $meta->version);
    }

    /** @return iterable<string, array{object, string}> */
    public static function invalidComponents(): iterable
    {
        yield 'untyped prop' => [new class extends Component {
            public $x;
            public function render(): mixed { return ''; }
        }, '#::\$x: must declare a single type \(string, int, float, bool, array, or a backed enum\)$#'];
        yield 'object prop' => [new class extends Component {
            public ?\DateTime $when = null;
            public function render(): mixed { return ''; }
        }, '#::\$when: type DateTime is not allowed \(use string, int, float, bool, array, or a backed enum\)$#'];
        yield 'readonly prop' => [new class extends Component {
            public readonly int $x;
            public function render(): mixed { return ''; }
        }, '#::\$x: component state can\'t be readonly$#'];
        yield 'reserved name c' => [new class extends Component {
            public int $c = 0;
            public function render(): mixed { return ''; }
        }, '#::\$c: the name is reserved \(c, errors, slot, and names starting with _\)$#'];
        yield 'reserved name errors' => [new class extends Component {
            public array $errors = [];
            public function render(): mixed { return ''; }
        }, '#::\$errors: the name is reserved#'];
        yield 'underscore prop' => [new class extends Component {
            public int $_zc = 0;
            public function render(): mixed { return ''; }
        }, '#::\$_zc: the name is reserved#'];
        yield 'static action' => [new class extends Component {
            #[Action] public static function go(): void {}
            public function render(): mixed { return ''; }
        }, '#::go\(\): \#\[Action\] must be on a public, non-static method of the component whose name doesn\'t start with _$#'];
        yield 'underscore action' => [new class extends Component {
            #[Action] public function _update(): void {}
            public function render(): mixed { return ''; }
        }, '#::_update\(\): \#\[Action\] must be on a public, non-static method#'];
        yield 'object param' => [new class extends Component {
            #[Action] public function go(\stdClass $o): void {}
            public function render(): mixed { return ''; }
        }, '#::go\(\) \$o: type stdClass is not allowed#'];
        yield 'variadic param' => [new class extends Component {
            #[Action] public function go(int ...$n): void {}
            public function render(): mixed { return ''; }
        }, '#::go\(\): action parameters can\'t be variadic or by-reference$#'];
        yield 'by-ref param' => [new class extends Component {
            #[Action] public function go(int &$n): void {}
            public function render(): mixed { return ''; }
        }, '#::go\(\): action parameters can\'t be variadic or by-reference$#'];
        yield 'required ctor' => [new class (1) extends Component {
            public function __construct(int $x) {}
            public function render(): mixed { return ''; }
        }, '#: the constructor must not require arguments \(use mount\(\) instead\)$#'];
    }

    #[DataProvider('invalidComponents')]
    public function testInvalidComponentsAreRefusedAtRegistration(object $instance, string $message): void
    {
        /** @var class-string<Component> $class */
        $class = $instance::class;
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches($message);
        Registry::register($class, 'bad');
    }

    public function testRegistrationMessagesNameTheClass(): void
    {
        try {
            Registry::register(\stdClass::class, 'x');
            $this->fail('accepted');
        } catch (\LogicException $e) {
            $this->assertSame('stdClass must extend ZealPHP\\Component\\Component', $e->getMessage());
        }
        $abstract = new class extends Component { public function render(): mixed { return ''; } };
        try {
            Registry::register(\ZealPHP\Tests\Unit\Component\Fixtures\Counter::class, 'one');
            Registry::register(\ZealPHP\Tests\Unit\Component\Fixtures\Guarded::class, 'one');
            $this->fail('clash accepted');
        } catch (\LogicException $e) {
            $this->assertSame("Component name 'one' is already used by ZealPHP\\Tests\\Unit\\Component\\Fixtures\\Counter", $e->getMessage());
        }
        try {
            Registry::register(\ZealPHP\Tests\Unit\Component\Fixtures\Counter::class, 'two');
            $this->fail('rename accepted');
        } catch (\LogicException $e) {
            $this->assertSame("ZealPHP\\Tests\\Unit\\Component\\Fixtures\\Counter is already registered as 'one'", $e->getMessage());
        }
        try {
            Registry::register(\ZealPHP\Tests\Unit\Component\Fixtures\Counter::class, 'Bad Name');
            $this->fail('bad name accepted');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString("Component name 'Bad Name' must be lowercase letters, digits and dashes", $e->getMessage());
        }
        unset($abstract);
    }

    public function testNonComponentClassIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        $class = \stdClass::class;
        Registry::register($class, 'x');
    }

    public function testModelRejectsLiveAndBlurTogether(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Model(live: true, blur: true);
    }

    public function testVersionChangesWithShape(): void
    {
        $a = new class extends Component {
            public int $n = 0;
            public function render(): mixed { return ''; }
        };
        $b = new class extends Component {
            public string $n = '';
            public function render(): mixed { return ''; }
        };
        $va = Registry::register($a::class, 'a')->version;
        $vb = Registry::register($b::class, 'b')->version;
        $this->assertNotSame($va, $vb);
    }

    // ── boot check ──────────────────────────────────────────────

    public function testBootCheckPassesWithoutComponentsOrWithAKey(): void
    {
        $this->assertNull(App::componentKeyBootCheck());
        Registry::register(Counter::class);
        AppKey::set(hash('sha256', 'k', true));
        $this->assertNull(App::componentKeyBootCheck());
    }

    public function testBootCheckRefusesWithoutAKey(): void
    {
        Registry::register(Counter::class);
        App::devReload(false);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('key:generate');
        App::componentKeyBootCheck();
    }

    public function testBootCheckUsesAnEphemeralKeyInDevMode(): void
    {
        Registry::register(Counter::class);
        App::devReload(true);
        $advisory = App::componentKeyBootCheck();
        $this->assertNotNull($advisory);
        $this->assertStringContainsString('random key', $advisory);
        $this->assertTrue(AppKey::isEphemeral());
    }
}
