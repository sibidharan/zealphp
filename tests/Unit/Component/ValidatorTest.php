<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Component;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ZealPHP\Component\Validator;

class ValidatorTest extends TestCase
{
    /** @return iterable<string, array{string, mixed, bool}> */
    public static function cases(): iterable
    {
        yield 'required empty' => ['required', '', false];
        yield 'required null' => ['required', null, false];
        yield 'required empty array' => ['required', [], false];
        yield 'required ok' => ['required', 'x', true];
        yield 'required zero' => ['required', 0, true];
        yield 'email ok' => ['email', 'a@b.co', true];
        yield 'email bad' => ['email', 'nope', false];
        yield 'email empty skipped' => ['email', '', true];
        yield 'url ok' => ['url', 'https://x.test/a', true];
        yield 'url javascript' => ['url', 'javascript:alert(1)', false];
        yield 'url ftp' => ['url', 'ftp://x.test', false];
        yield 'min string' => ['min:3', 'ab', false];
        yield 'min string utf8' => ['min:3', '日本語', true];
        yield 'min number' => ['min:18', 17, false];
        yield 'max array' => ['max:2', [1, 2, 3], false];
        yield 'max ok' => ['max:5', 'hello', true];
        yield 'between in' => ['between:2,4', 3, true];
        yield 'between out' => ['between:2,4', 5, false];
        yield 'in ok' => ['in:a,b', 'b', true];
        yield 'in bad' => ['in:a,b', 'c', false];
        yield 'not_in' => ['not_in:a,b', 'a', false];
        yield 'regex ok' => ['regex:/^[a-z]+$/', 'abc', true];
        yield 'regex bad' => ['regex:/^[a-z]+$/', 'AB', false];
        yield 'regex with pipe' => ['regex:/^(a|b)$/', 'b', true];
        yield 'numeric' => ['numeric', '1.5', true];
        yield 'numeric bad' => ['numeric', 'x', false];
        yield 'integer' => ['integer', '-3', true];
        yield 'integer bad' => ['integer', '1.5', false];
        yield 'alpha' => ['alpha', 'café', true];
        yield 'alpha bad' => ['alpha', 'a1', false];
        yield 'alpha_num' => ['alpha_num', 'a1', true];
        yield 'alpha_dash' => ['alpha_dash', 'a-b_c', true];
        yield 'alpha_dash bad' => ['alpha_dash', 'a b', false];
        yield 'accepted' => ['accepted', true, true];
        yield 'accepted on' => ['accepted', 'on', true];
        yield 'accepted false' => ['accepted', false, false];
        yield 'chain stops at first' => ['required|email', '', false];
        yield 'alpha anchored start' => ['alpha', '1a', false];
        yield 'alpha anchored end' => ['alpha', 'a1', false];
        yield 'alpha_num anchored start' => ['alpha_num', '-a1', false];
        yield 'alpha_num anchored end' => ['alpha_num', 'a1-', false];
        yield 'alpha_num unicode' => ['alpha_num', 'café٣', true];
        yield 'alpha_dash unicode' => ['alpha_dash', 'é-1_', true];
        yield 'alpha_num not string' => ['alpha_num', 5, false];
        yield 'url must start with scheme' => ['url', 'ftp://x.test/?u=https://y', false];
        yield 'url upper scheme' => ['url', 'HTTPS://x.test', true];
        yield 'url not string' => ['url', 5, false];
        yield 'between lower bound' => ['between:2,4', 2, true];
        yield 'between upper bound' => ['between:2,4', 4, true];
        yield 'between below' => ['between:2,4', 1, false];
        yield 'max counts characters not bytes' => ['max:3', '日本語', true];
        yield 'min counts characters not bytes' => ['min:4', '日本語', false];
        yield 'in non-scalar' => ['in:a', ['a'], false];
        yield 'not_in ok' => ['not_in:a,b', 'c', true];
        yield 'not_in non-scalar' => ['not_in:a', ['x'], false];
        yield 'numeric int' => ['numeric', 3, true];
        yield 'numeric float' => ['numeric', 1.5, true];
        yield 'integer native' => ['integer', 7, true];
        yield 'integer float' => ['integer', 7.0, false];
        yield 'accepted 1' => ['accepted', 1, true];
        yield 'accepted 0' => ['accepted', 0, false];
        yield 'accepted string true' => ['accepted', 'true', true];
        yield 'regex non-scalar' => ['regex:/a/', ['a'], false];
        yield 'email non-string' => ['email', 5, false];
        // `$` alone matches before a trailing newline; the rules must not.
        yield 'integer trailing newline' => ['integer', "5\n", false];
        yield 'alpha trailing newline' => ['alpha', "abc\n", false];
        yield 'alpha_num trailing newline' => ['alpha_num', "a1\n", false];
        yield 'alpha_dash trailing newline' => ['alpha_dash', "a-b\n", false];
    }

    #[DataProvider('cases')]
    public function testRules(string $rules, mixed $value, bool $passes): void
    {
        $errors = Validator::validate(['f' => $value], ['f' => $rules]);
        $this->assertSame($passes, $errors === [], json_encode($errors) ?: '');
    }

    public function testMessagesUseTheFieldLabelAndCanBeOverridden(): void
    {
        $this->assertSame(
            ['first_name' => 'First name is required.'],
            Validator::validate(['first_name' => ''], ['first_name' => 'required'])
        );
        $this->assertSame(
            ['userName' => 'User name must be at least 3 characters.'],
            Validator::validate(['userName' => 'ab'], ['userName' => 'min:3'])
        );
        $this->assertSame(
            ['a' => 'Need a!'],
            Validator::validate(['a' => ''], ['a' => 'required'], ['a.required' => 'Need a!'])
        );
        $this->assertSame(
            ['a' => 'A missing'],
            Validator::validate(['a' => ''], ['a' => 'required'], ['required' => ':field missing'])
        );
    }

    /** @return iterable<string, array{string, mixed, string}> */
    public static function messages(): iterable
    {
        yield 'required' => ['required', '', 'F is required.'];
        yield 'accepted' => ['accepted', false, 'F must be accepted.'];
        yield 'email' => ['email', 'x', 'F must be a valid email address.'];
        yield 'url' => ['url', 'x', 'F must be a valid URL.'];
        yield 'min string' => ['min:3', 'ab', 'F must be at least 3 characters.'];
        yield 'min number' => ['min:3', 2, 'F must be at least 3.'];
        yield 'max array' => ['max:1', [1, 2], 'F must be at most 1 items.'];
        yield 'between' => ['between:2,4', 'a', 'F must be between 2 and 4 characters.'];
        yield 'in' => ['in:a', 'b', 'F is not a valid choice.'];
        yield 'not_in' => ['not_in:a', 'a', 'F is not a valid choice.'];
        yield 'numeric' => ['numeric', 'x', 'F must be a number.'];
        yield 'integer' => ['integer', 'x', 'F must be a whole number.'];
        yield 'alpha' => ['alpha', '1', 'F may only contain letters.'];
        yield 'alpha_num' => ['alpha_num', '-', 'F may only contain letters and numbers.'];
        yield 'alpha_dash' => ['alpha_dash', ' ', 'F may only contain letters, numbers, dashes and underscores.'];
        yield 'regex' => ['regex:/^a$/', 'b', 'F is invalid.'];
    }

    #[DataProvider('messages')]
    public function testDefaultMessages(string $rule, mixed $value, string $message): void
    {
        $this->assertSame(['f' => $message], Validator::validate(['f' => $value], ['f' => $rule]));
    }

    public function testFieldSpecificMessageBeatsRuleMessage(): void
    {
        $this->assertSame(['a' => 'specific'], Validator::validate(['a' => ''], ['a' => 'required'], ['required' => 'general', 'a.required' => 'specific']));
    }

    public function testEveryFieldIsCheckedAndMissingValuesAreNull(): void
    {
        $this->assertSame(
            ['a' => 'A is required.', 'b' => 'B is required.'],
            Validator::validate([], ['a' => 'required', 'b' => 'required', 'c' => 'email'])
        );
    }

    public function testLabels(): void
    {
        $this->assertSame('First name', Validator::label('first_name'));
        $this->assertSame('First name', Validator::label('firstName'));
        $this->assertSame('Zip code', Validator::label('zip-code'));
        $this->assertSame('X', Validator::label('_x_'));
    }

    public function testClosureRules(): void
    {
        $rules = ['code' => ['required', fn($v) => $v === 'ok' ? null : 'Wrong code.']];
        $this->assertSame([], Validator::validate(['code' => 'ok'], $rules));
        $this->assertSame(['code' => 'Wrong code.'], Validator::validate(['code' => 'no'], $rules));
        $this->assertSame(['x' => 'X is invalid.'], Validator::validate(['x' => 1], ['x' => [fn() => false]]));
    }

    public function testParse(): void
    {
        $this->assertSame(['required', 'min:3', 'regex:/a|b/'], Validator::parse(' required | min:3 |regex:/a|b/'));
        $this->assertSame(['required', 'email'], Validator::parse('required||email|'));
        $this->assertSame(['in:a:b'], Validator::parse('in:a:b'));
        $this->assertSame([], Validator::parse(''));
    }

    /** @return iterable<string, array{string}> */
    public static function badRules(): iterable
    {
        yield 'unknown' => ['frobnicate'];
        yield 'min without number' => ['min:x'];
        yield 'between one value' => ['between:1'];
        yield 'invalid regex' => ['regex:/(/'];
    }

    #[DataProvider('badRules')]
    public function testMisconfiguredRulesThrow(string $rule): void
    {
        $this->expectException(\LogicException::class);
        Validator::validate(['f' => 'value'], ['f' => $rule]);
    }
}
