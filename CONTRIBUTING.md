# Contributing to ZealPHP

Thanks for your interest in contributing to ZealPHP, a coroutine-native PHP web framework built on OpenSwoole. Contributions of all kinds are welcome -- bug fixes, new features, documentation improvements, and test coverage.

## Prerequisites

- PHP 8.3 or newer
- [OpenSwoole](https://openswoole.com/) extension
- [uopz](https://pecl.php.net/package/uopz) extension
- [Composer](https://getcomposer.org/)

## Setup

```bash
git clone https://github.com/<your-fork>/zealphp.git
cd zealphp
composer install
php app.php          # starts the dev server on http://localhost:8080
```

## Contribution Workflow

1. **Fork** the repository and clone your fork locally.
2. **Create a branch** from `master` for your change: `git checkout -b my-feature`
3. **Make your changes** -- keep commits focused and well-described.
4. **Run the tests** (see below) and make sure they pass.
5. **Push** your branch and open a **Pull Request** against `master`.

## Testing

Unit tests run without a server:

```bash
./vendor/bin/phpunit tests/Unit/ --testdox
```

Integration tests require the dev server to be running on port 8080:

```bash
php app.php &
./vendor/bin/phpunit tests/Integration/ --testdox
```

Run the full suite:

```bash
./vendor/bin/phpunit --testdox
```

## Code Style

- Follow [PSR-12](https://www.php-fig.org/psr/psr-12/) coding standards.
- Use short array syntax (`[]` not `array()`).
- Add `declare(strict_types=1)` where possible.
- Keep functions and methods short and focused.

## Reporting Issues

### Bug Reports

Please include:
- PHP version (`php -v`)
- OpenSwoole version (`php --ri openswoole`)
- Steps to reproduce the issue
- Expected vs. actual behavior
- Any relevant logs or error output

### Feature Requests

Describe the use case you are trying to solve, not just the solution you have in mind. This helps us find the best approach.

## Pull Request Guidelines

- Keep PRs focused on a single change.
- Pair code changes with documentation or test updates when applicable.
- Explain **what** changed, **why**, and **how to test** in the PR description.
- Make sure all tests pass before requesting review.
- Avoid breaking changes unless discussed in an issue first.

## License

By contributing, you agree that your contributions will be licensed under the [MIT License](LICENSE).
