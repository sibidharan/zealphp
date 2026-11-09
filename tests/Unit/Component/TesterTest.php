<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Component;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use ZealPHP\App;
use ZealPHP\AppKey;
use ZealPHP\Component\HtmxBinder;
use ZealPHP\Component\Registry;
use ZealPHP\Component\Tester;
use ZealPHP\RequestContext;
use ZealPHP\Tests\Unit\Component\Fixtures\Counter;
use ZealPHP\Tests\Unit\Component\Fixtures\Guarded;
use ZealPHP\Tests\Unit\Component\Fixtures\Signup;
use ZealPHP\Tests\Unit\Component\Fixtures\Size;

/** Tester: driving components through the real endpoint in a unit test. */
class TesterTest extends TestCase
{
    private string $prevCwd = '';

    protected function setUp(): void
    {
        $this->prevCwd = App::$cwd;
        App::$cwd = ZEALPHP_ROOT . '/tests/fixtures/components';
        App::superglobals(false);
        AppKey::reset();
        Registry::reset();
        App::componentBinder(new HtmxBinder());
        $g = RequestContext::instance();
        $g->memo = [];
        unset($g->session); // no session: the tester must still pass the origin check
        $g->cookie = [];
    }

    protected function tearDown(): void
    {
        Registry::reset();
        AppKey::reset();
        App::componentBinder(new HtmxBinder());
        App::$cwd = $this->prevCwd;
        $g = RequestContext::instance();
        $g->memo = [];
        $g->session = [];
    }

    public function testActionsChangeStateAndMarkup(): void
    {
        $t = Tester::render(Counter::class, ['count' => 1])
            ->assertSet('count', 1)
            ->call('increment', ['by' => 2])
            ->assertStatus(200)
            ->assertSet('count', 3)
            ->assertSee('3')
            ->call('increment')
            ->assertSet('count', 4);
        $this->assertTrue(AppKey::isEphemeral(), 'no key configured: a random one for the process');
        $this->assertStringContainsString('data-zc="counter"', $t->html());
        $this->assertSame(1, $t->get('mounted'), 'mount() runs once, on the first render');
        $t->call('setTo', ['value' => 9, 'size' => Size::Large->value])->assertSet('count', 9);
    }

    public function testRegisteredNamesAndExistingRegistrations(): void
    {
        App::components([Counter::class]);
        Tester::render('counter')->call('increment')->assertSet('count', 1);
        Tester::render(Counter::class)->assertSet('count', 0);
        $this->expectException(\InvalidArgumentException::class);
        Tester::render('no-such-component');
    }

    public function testSettingAModelValidatesAndRunsItsHook(): void
    {
        $t = Tester::render(Signup::class)
            ->set('username', 'ab')
            ->assertStatus(422)
            ->assertHasErrors('username')
            ->set('username', 'abc')
            ->assertHasNoErrors()
            ->assertSet('username', 'abc')
            ->assertSet('usernameChanges', 2)
            ->set('username', 'taken')
            ->assertHasErrors('username');
        $this->assertSame('That username is taken.', $t->errors()['username']);
    }

    public function testSetKeepsTheOtherBoundFields(): void
    {
        // A browser posts every bound control; an omitted checkbox means unchecked.
        Tester::render(Signup::class, ['agree' => true, 'tags' => ['a', 'b'], 'size' => Size::Large, 'newsletter' => null])
            ->set('email', 'a@b.co')
            ->assertSet('agree', true)
            ->assertSet('tags', ['a', 'b'])
            ->assertSet('size', Size::Large)
            ->assertSet('email', 'a@b.co')
            ->set('agree', false)
            ->assertSet('agree', false);
    }

    public function testEventsRedirectsAndValidationOnActions(): void
    {
        $t = Tester::render(Signup::class)->call('save')->assertStatus(422)->assertHasErrors('email', 'username');
        $this->assertSame([], $t->events());

        $t = Tester::render(Signup::class, ['email' => 'a@b.co', 'username' => 'alice'])
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('saved', true)
            ->assertDispatched('signed-up');
        $this->assertSame(['signed-up' => ['email' => 'a@b.co']], $t->events());
        $this->assertNull($t->redirectedTo());

        $t = Tester::render(Signup::class)->call('leave')->assertRedirect()->assertRedirect('/bye');
        $this->assertSame('/bye', $t->redirectedTo());
    }

    public function testRefusalsKeepTheMarkup(): void
    {
        $t = Tester::render(Guarded::class);
        $before = $t->html();
        $t->call('secret')->assertStatus(403);
        $this->assertSame($before, $t->html());
        $t->call('missing')->assertStatus(404);
        $t->call('open')->assertStatus(200)->assertSet('note', 'open')->assertSee('open')->assertDontSee('ran');
        $t->refresh()->assertStatus(200)->assertSet('note', 'open');
    }

    public function testFailedAssertionsReportWhatWasExpected(): void
    {
        $t = Tester::render(Counter::class, ['count' => 1]);
        foreach ([
            static fn() => $t->assertSet('count', 2),
            static fn() => $t->assertSee('nope'),
            static fn() => $t->assertDontSee('1'),
            static fn() => $t->assertHasErrors(),
            static fn() => $t->assertHasErrors('count'),
            static fn() => $t->assertDispatched('x'),
            static fn() => $t->assertRedirect(),
            static fn() => $t->assertRedirect('/x'),
            static fn() => $t->assertStatus(500),
        ] as $i => $assertion) {
            try {
                $assertion();
                $this->fail("assertion {$i} should have failed");
            } catch (AssertionFailedError $e) {
                $this->assertNotSame('', $e->getMessage());
                $this->assertStringNotContainsString("assertion {$i} should have failed", $e->getMessage());
            }
        }
    }

    public function testEscapingInAssertSee(): void
    {
        $t = Tester::render(Guarded::class)->call('open');
        $t->assertSee('<p>open</p>', false);
        $this->expectException(AssertionFailedError::class);
        $t->assertSee('<p>open</p>'); // escaped by default: '&lt;p&gt;' is not in the markup
    }

    public function testUnknownPropertiesAreRefused(): void
    {
        $t = Tester::render(Signup::class);
        try {
            $t->get('nope');
            $this->fail('unknown property');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(\InvalidArgumentException::class);
        $t->set('saved', true); // public, but not a #[Model]
    }
}
