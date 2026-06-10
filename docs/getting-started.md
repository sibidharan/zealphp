# Getting Started

This guide walks through setting up a workstation capable of running ZealPHP, verifying prerequisite extensions, creating a new project, and launching the OpenSwoole HTTP server.

## 1. Prerequisites

- **Operating system**: Linux distribution with access to `apt`, or macOS/Homebrew with equivalent packages.
- **PHP**: >= 8.3 CLI with development headers. Tested on 8.3 and 8.4; **PHP 8.5 is supported on OpenSwoole 26.2+** (the CI matrix runs `phpunit (PHP 8.5)`).
- **OpenSwoole**: PECL package, `22.1+` (current channel: `openswoole-22.1.5` or later). `composer.json` pins `ext-openswoole: ">=22.0"`; **OpenSwoole 26.2+ (released Feb 2026) adds PHP 8.5 support**. Compile with coroutine sockets, OpenSSL, HTTP/2, MySQLnd, CURL, and Postgres support.
- **ext-zealphp** (recommended) or **uopz** (fallback): used to override built-in PHP functions inside the ZealPHP runtime. ZealPHP requires at least one; it prefers ext-zealphp when loaded. ext-zealphp is also required for `coroutine-legacy` mode.
- **Composer**: dependency manager for PHP projects.

Install the core toolchain using `apt` (recommended):

```bash
sudo apt update
sudo apt install gcc php-dev \
  openssl libssl-dev curl libcurl4-openssl-dev libpcre3-dev build-essential \
  php8.3-mysqlnd postgresql libpq-dev composer

sudo pecl install openswoole-22.1.5     # or a newer 22.1.x / 26.2+ release

# ext-zealphp (ZealPHP's own extension) — via PIE or from source:
pie install zealphp/ext
# Or: git clone --depth 1 https://github.com/sibidharan/ext-zealphp.git /tmp/ext-zealphp
#     cd /tmp/ext-zealphp && phpize && ./configure && make && sudo make install
```

When prompted during the OpenSwoole build, answer **yes** to the coroutine and protocol questions so that features such as coroutine sockets and HTTP/2 are enabled.

> **Automation**: the repository ships with `setup.sh` that runs the same installation steps. Inspect it before execution if you are operating in a restricted environment.

### PHP Extension Configuration

After installing the PECL packages, enable them in the CLI configuration:

```bash
sudo tee /etc/php/8.3/cli/conf.d/99-zealphp-openswoole.ini <<'EOF'
extension=openswoole.so
extension=zealphp.so
extension=uopz.so
short_open_tag=on
EOF
```

Verify the modules are loaded:

```bash
php -m | grep openswoole
php -m | grep zealphp   # recommended override engine
php -m | grep uopz      # fallback (only needed if ext-zealphp is absent)
```

At minimum `openswoole` and one of `zealphp` or `uopz` must print the module name.

> **Session unserialize whitelist (v0.2.26).** When ZealPHP virtualizes `session_start()` (via ext-zealphp or uopz), it reads `$_SESSION` blobs through `unserialize()` with `['allowed_classes' => ['stdClass']]`. Scalars, arrays, and `stdClass` round-trip normally; any other class read back from session storage becomes `__PHP_Incomplete_Class`. Adding a class to the whitelist requires reviewing its `__wakeup` / `__unserialize` / `__destruct` magic methods first. See `src/Session/utils.php` and the `template/pages/sessions.php#objects-in-session` page for the canonical reference.

## 2. Clone and Install Dependencies

Clone the framework and install Composer dependencies:

```bash
git clone https://github.com/sibidharan/zealphp.git
cd zealphp
composer install
```

Composer registers the PSR-4 autoloader for the `ZealPHP` namespace and pulls in OpenSwoole IDE helpers for better editor integration.

## 3. Configure Your IDE

- Add `swoole` to the Intelephense stubs list.
- Include `vendor/openswoole/ide-helper` in your editor’s include paths.
- Enable short open tags if your editor validates PHP templates (`<?` is widely used inside ZealPHP template files).

## 4. Boot the Development Server

`app.php` is the binary entrypoint that initializes the ZealPHP runtime, wires middleware, and starts the OpenSwoole HTTP server. Run it directly from the project root:

```bash
php app.php
```

Expected output:

```
ZealPHP server running at http://0.0.0.0:8080 with N routes
```

**Worker count default.** When `ZEALPHP_WORKERS` is unset, ZealPHP calls
`\ZealPHP\default_worker_count(4)`, which resolves to `min(4, floor(cgroup CPU quota))`.
In a Docker container or Kubernetes pod with a CPU limit (e.g. `--cpus=2`), this
keeps the worker count proportional to the allocated CPUs rather than spawning one
worker per host core. Set `ZEALPHP_WORKERS` explicitly to override.

Visit `http://localhost:8080` in your browser to exercise the implicit public routes that map to files in `public/` — the **document root** (the Apache `DocumentRoot` equivalent). It defaults to `public/`; change it with `App::documentRoot('…')` before `App::init()`.

## 5. Verifying Health

1. Open a terminal and request a simple page:
   ```bash
   curl -i http://localhost:8080/about
   ```
   You should see a 200 status and the contents of `public/about.php`.
2. Hit an API endpoint:
   ```bash
   curl -i http://localhost:8080/api/device/list
   ```
   Expect a JSON response defined in `api/device/list.php`.
3. Tail application logs (`logs/` if configured) or review console output for errors thrown during the request lifecycle.

If the server fails to start with `Class "OpenSwoole\HTTP\Server" not found`, double-check that `extension=openswoole.so` is active for the PHP binary you are using.

## 6. Next Steps

- Review [directory-structure.md](directory-structure.md) to understand how ZealPHP arranges routes, APIs, templates, and background tasks.
- Read [runtime-architecture.md](runtime-architecture.md) to learn how ZealPHP virtualizes superglobals, manages sessions, and bridges PSR interfaces with OpenSwoole.
