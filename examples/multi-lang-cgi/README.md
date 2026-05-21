# multi-lang-cgi — Per-extension CGI backend demo

Demonstrates `App::registerCgiBackend()`: route `.pl` files through Perl,
`.cgi` via shebang, and `.php` through the default ZealPHP CGI worker.
A commented-out `.py` block shows the FastCGI path for Python.

## Prerequisites

- PHP 8.3+ with OpenSwoole
- Perl (`/usr/bin/perl`) for `info.pl`
- Optional: a Python FastCGI server (flup / gunicorn) for `hello.py`

## Run

```bash
cd examples/multi-lang-cgi
php app.php
```

Then open:

| URL | Handler |
|-----|---------|
| `http://localhost:8099/info.pl` | Perl via `proc` mode + `/usr/bin/perl` |
| `http://localhost:8099/index.php` | PHP via default `proc` mode (cgi_worker.php) |

## What each registration shows

```php
// Perl — proc mode with explicit interpreter
App::registerCgiBackend('.pl', [
    'mode'        => 'proc',
    'interpreter' => '/usr/bin/perl',
]);

// Shebang-style CGI — proc mode, no interpreter override
App::registerCgiBackend('.cgi', ['mode' => 'proc']);

// Python FastCGI — fcgi mode, requires a running FastCGI server
App::registerCgiBackend('.py', [
    'mode'    => 'fcgi',
    'address' => '127.0.0.1:9001',
    'fcgi_params' => ['SCRIPT_ROOT' => __DIR__ . '/public'],
]);
```

## Directory tree

```
examples/multi-lang-cgi/
  app.php          — ZealPHP bootstrap with registerCgiBackend() calls
  public/
    hello.py       — Minimal Python CGI script (CGI/1.1 output)
    info.pl        — Minimal Perl CGI script
  README.md        — This file
```
