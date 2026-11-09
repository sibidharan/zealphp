<?php

declare(strict_types=1);

namespace ZealPHP\Component;

/**
 * Validation for component input. Rules are a `|`-separated string
 * (`'required|email|max:120'`) or a list mixing rule strings and closures
 * `fn(mixed $value, string $field): ?string` that return an error message or null.
 *
 * Syntax: `name` or `name:argument`, joined by `|`. List arguments are
 * comma-separated (`in:free,pro`, `between:3,20`); `regex:` takes the rest of
 * the string. Rules run left to right and stop at the first failure, so each
 * field gets at most one message. A malformed rule (unknown name, `min:x`,
 * a bad pattern) throws a LogicException: at registration for
 * `#[Model(rules: …)]`, otherwise on the first validation, empty value or not.
 *
 * Rules:
 *
 * | Rule | Passes when |
 * |---|---|
 * | `required` | not null, not `''`, not `[]` (and `false` fails for booleans, like a checkbox that must be ticked: use `accepted`) |
 * | `email` | a valid email address |
 * | `url` | an http(s) URL |
 * | `min:n` / `max:n` | string length, number value, or array count is ≥ n / ≤ n |
 * | `between:a,b` | as min:a and max:b |
 * | `in:a,b,c` / `not_in:a,b,c` | the value (as a string) is / isn't in the list |
 * | `regex:/pattern/` | matches; put it last or use the list form if the pattern contains `\|` |
 * | `numeric`, `integer` | numeric / a whole number |
 * | `alpha`, `alpha_num`, `alpha_dash` | letters / letters+digits / plus `-` and `_` |
 * | `accepted` | `true`, `1`, `'1'`, `'on'`, `'yes'`, `'true'` |
 *
 * Every rule except `required` and `accepted` skips empty values, so
 * `'email'` alone allows an empty field and `'required|email'` doesn't.
 */
final class Validator
{
    /** Rules that take no argument. */
    private const FLAGS = ['required', 'accepted', 'email', 'url', 'numeric', 'integer', 'alpha', 'alpha_num', 'alpha_dash'];

    /**
     * Validate each field and return the first error per failing field.
     *
     * @param array<string, mixed>                       $values
     * @param array<string, string|list<string|\Closure>> $rules
     * @param array<string, string>                       $messages `'field.rule' => 'message'` or `'rule' => 'message'`
     * @return array<string, string>
     */
    public static function validate(array $values, array $rules, array $messages = []): array
    {
        $errors = [];
        foreach ($rules as $field => $fieldRules) {
            $value = $values[$field] ?? null;
            foreach (self::parse($fieldRules) as $rule) {
                if (is_string($rule)) {
                    self::assertRule($rule, (string) $field);
                }
                $error = $rule instanceof \Closure
                    ? self::runClosure($rule, $value, $field)
                    : self::check($rule, $value, $field, $messages);
                if ($error !== null) {
                    $errors[$field] = $error;
                    break;
                }
            }
        }
        return $errors;
    }

    /**
     * @param string|list<string|\Closure> $rules
     * @return list<string|\Closure>
     */
    public static function parse(string|array $rules): array
    {
        if (is_array($rules)) {
            return $rules;
        }
        $out = [];
        $rest = trim($rules);
        while ($rest !== '') {
            // `regex:` takes the rest of the string so patterns may contain `|`.
            if (str_starts_with($rest, 'regex:')) {
                $out[] = $rest;
                break;
            }
            $pos = strpos($rest, '|');
            $out[] = trim($pos === false ? $rest : substr($rest, 0, $pos));
            $rest = $pos === false ? '' : trim(substr($rest, $pos + 1));
        }
        return array_values(array_filter($out, static fn(string $r): bool => $r !== ''));
    }

    /**
     * Throw if any rule is malformed: an unknown name, a missing or
     * non-numeric argument, an argument on a rule that takes none, or a
     * pattern that doesn't compile. Closures are not checked.
     *
     * @param string|list<string|\Closure> $rules
     * @throws \LogicException
     */
    public static function assertRules(string|array $rules, string $field): void
    {
        foreach (self::parse($rules) as $rule) {
            if (is_string($rule)) {
                self::assertRule($rule, $field);
            }
        }
    }

    private static function assertRule(string $rule, string $field): void
    {
        [$name, $arg] = array_pad(explode(':', $rule, 2), 2, '');
        $where = "validation rule '{$rule}' on {$field}";
        if (in_array($name, self::FLAGS, true)) {
            if ($arg !== '') {
                throw new \LogicException("Invalid {$where}: '{$name}' takes no argument");
            }
            return;
        }
        switch ($name) {
            case 'min':
            case 'max':
                self::number($arg, $name);
                return;
            case 'between':
                $bounds = explode(',', $arg);
                if (count($bounds) !== 2) {
                    throw new \LogicException("Validation rule 'between' needs two values on {$field}");
                }
                self::number($bounds[0], 'between');
                self::number($bounds[1], 'between');
                return;
            case 'in':
            case 'not_in':
                if ($arg === '') {
                    throw new \LogicException("Invalid {$where}: '{$name}' needs a list, like {$name}:a,b");
                }
                return;
            case 'regex':
                if (@preg_match($arg, '') === false) {
                    throw new \LogicException("Invalid regex in validation rule for {$field}: {$arg}");
                }
                return;
            default:
                throw new \LogicException("Unknown validation rule '{$name}' on {$field}");
        }
    }

    private static function runClosure(\Closure $rule, mixed $value, string $field): ?string
    {
        $result = $rule($value, $field);
        if ($result === null || $result === true) {
            return null;
        }
        return is_string($result) && $result !== '' ? $result : self::label($field) . ' is invalid.';
    }

    /**
     * @param array<string, string> $messages
     */
    private static function check(string $rule, mixed $value, string $field, array $messages): ?string
    {
        [$name, $arg] = array_pad(explode(':', $rule, 2), 2, '');
        $empty = $value === null || $value === '' || $value === [];

        $ok = match ($name) {
            'required' => !$empty,
            'accepted' => in_array($value, [true, 1, '1', 'on', 'yes', 'true'], true),
            default => $empty || self::passes($name, $arg, $value, $field),
        };
        if ($ok) {
            return null;
        }
        $message = $messages["{$field}.{$name}"] ?? $messages[$name] ?? self::defaultMessage($name, $arg, $value);
        return str_replace(':field', self::label($field), $message);
    }

    private static function passes(string $name, string $arg, mixed $value, string $field): bool
    {
        switch ($name) {
            case 'email':
                return is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
            case 'url':
                return is_string($value) && filter_var($value, FILTER_VALIDATE_URL) !== false
                    && preg_match('#^https?://#i', $value) === 1;
            case 'min':
                return self::size($value) >= self::number($arg, 'min');
            case 'max':
                return self::size($value) <= self::number($arg, 'max');
            case 'between':
                // assertRule() has checked the two bounds.
                [$low, $high] = array_pad(explode(',', $arg, 2), 2, '');
                $size = self::size($value);
                return $size >= self::number($low, 'between') && $size <= self::number($high, 'between');
            case 'in':
                return is_scalar($value) && in_array((string) $value, explode(',', $arg), true);
            case 'not_in':
                return is_scalar($value) && !in_array((string) $value, explode(',', $arg), true);
            case 'regex':
                // assertRule() has checked that the pattern compiles.
                return is_scalar($value) && preg_match($arg, (string) $value) === 1;
            case 'numeric':
                return is_int($value) || is_float($value) || (is_string($value) && is_numeric($value));
            case 'integer':
                return is_int($value) || (is_string($value) && preg_match('/^-?\d+$/D', $value) === 1);
            case 'alpha':
                return is_string($value) && preg_match('/^\p{L}+$/uD', $value) === 1;
            case 'alpha_num':
                return is_string($value) && preg_match('/^[\p{L}\p{N}]+$/uD', $value) === 1;
            case 'alpha_dash':
                return is_string($value) && preg_match('/^[\p{L}\p{N}_-]+$/uD', $value) === 1;
            default:
                throw new \LogicException("Unknown validation rule '{$name}' on {$field}");
        }
    }

    private static function size(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_array($value)) {
            return (float) count($value);
        }
        if (is_scalar($value)) {
            return (float) mb_strlen((string) $value);
        }
        return 0.0;
    }

    private static function number(string $arg, string $rule): float
    {
        if (!is_numeric($arg)) {
            throw new \LogicException("Validation rule '{$rule}' needs a number, got '{$arg}'");
        }
        return (float) $arg;
    }

    private static function defaultMessage(string $name, string $arg, mixed $value): string
    {
        $unit = is_string($value) ? ' characters' : (is_array($value) ? ' items' : '');
        return match ($name) {
            'required' => ':field is required.',
            'accepted' => ':field must be accepted.',
            'email' => ':field must be a valid email address.',
            'url' => ':field must be a valid URL.',
            'min' => ":field must be at least {$arg}{$unit}.",
            'max' => ":field must be at most {$arg}{$unit}.",
            'between' => ':field must be between ' . str_replace(',', ' and ', $arg) . "{$unit}.",
            'in', 'not_in' => ':field is not a valid choice.',
            'numeric' => ':field must be a number.',
            'integer' => ':field must be a whole number.',
            'alpha' => ':field may only contain letters.',
            'alpha_num' => ':field may only contain letters and numbers.',
            'alpha_dash' => ':field may only contain letters, numbers, dashes and underscores.',
            default => ':field is invalid.',
        };
    }

    /** `first_name` / `firstName` → `First name`. */
    public static function label(string $field): string
    {
        $words = strtolower(trim((string) preg_replace('/([a-z])([A-Z])|[_-]+/', '$1 $2', $field)));
        return ucfirst((string) preg_replace('/\s+/', ' ', $words));
    }
}
