# Getting Started

This guide walks through setting up a workstation capable of running ZealPHP, verifying prerequisite extensions, creating a new project, and launching the OpenSwoole HTTP server.

## 1. Prerequisites

- **Operating system**: Linux distribution with access to `apt`, or macOS/Homebrew with equivalent packages.
- **PHP**: >= 8.3 CLI with development headers.
- **OpenSwoole**: PECL package `openswoole-22.1.2` compiled with coroutine sockets, OpenSSL, HTTP/2, MySQLnd, CURL, and Postgres support.
- **uopz**: PECL package used to override built-in PHP functions inside the ZealPHP runtime.
- **Composer**: dependency manager for PHP projects.

Install the core toolchain using `apt` (recommended):

```bash
sudo apt update
sudo apt install gcc php-dev \
  openssl libssl-dev curl libcurl4-openssl-dev libpcre3-dev build-essential \
  php8.3-mysqlnd postgresql libpq-dev composer

sudo pecl install uopz
sudo pecl install openswoole-22.1.2
```

When prompted during the OpenSwoole build, answer **yes** to the coroutine and protocol questions so that features such as coroutine sockets and HTTP/2 are enabled.

> **Automation**: the repository ships with `setup.sh` that runs the same installation steps. Inspect it before execution if you are operating in a restricted environment.

### PHP Extension Configuration

After installing the PECL packages, enable them in the CLI configuration:

```bash
sudo tee /etc/php/8.3/cli/conf.d/99-zealphp-openswoole.ini <<'EOF'
extension=openswoole.so
extension=uopz.so
short_open_tag=on
EOF
```

Verify the modules are loaded:

```bash
php -m | grep openswoole
php -m | grep uopz
```

Both commands should print the module name.

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
ZealPHP server running at http://0.0.0.0:9501 with N routes
```

Visit `http://localhost:9501` in your browser to exercise the implicit public routes that map to files in `public/`.

## 5. Verifying Health

1. Open a terminal and request a simple page:
   ```bash
   curl -i http://localhost:9501/about
   ```
   You should see a 200 status and the contents of `public/about.php`.
2. Hit an API endpoint:
   ```bash
   curl -i http://localhost:9501/api/device/list
   ```
   Expect a JSON response defined in `api/device/list.php`.
3. Tail application logs (`logs/` if configured) or review console output for errors thrown during the request lifecycle.

If the server fails to start with `Class "Swoole\HTTP\Server" not found`, double-check that `extension=openswoole.so` is active for the PHP binary you are using.

## 6. Next Steps

- Review [directory-structure.md](directory-structure.md) to understand how ZealPHP arranges routes, APIs, templates, and background tasks.
- Read [runtime-architecture.md](runtime-architecture.md) to learn how ZealPHP virtualizes superglobals, manages sessions, and bridges PSR interfaces with OpenSwoole.
