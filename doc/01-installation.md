# 01 – Installation & First Run

## Table of Contents

- [1. PHP extensions](#1-php-extensions)
- [2. Create a new project](#2-create-a-new-project)
- [3. Start the server](#3-start-the-server)
- [4. Running as a daemon (production)](#4-running-as-a-daemon-production)
- [5. IDE helpers](#5-ide-helpers)

> **TL;DR** – Install OpenSwoole & `uopz`, run `composer create-project sibidharan/zealphp-project my-app`, then `php app.php`.  A development server is waiting for you on <http://localhost:8080>.

ZealPHP is built on top of the [OpenSwoole](https://openswoole.com/) asynchronous runtime.  The server process lives **inside** PHP – no additional web-server such as Apache or Nginx is needed while you develop.

---

## 1. PHP extensions

ZealPHP requires two PECL extensions:

1. **uopz** – lets ZealPHP monkey-patch native PHP functions (`header`, `setcookie`, `session_*`) so that they work inside OpenSwoole’s event loop.
2. **openswoole** – the async engine providing the HTTP server, coroutines, sockets, etc.

```bash
sudo pecl install uopz
sudo pecl install openswoole-22.1.2   # pick a version compatible with your PHP build
```

When the OpenSwoole installer asks questions, enable at least the following:

* coroutine sockets – **yes**
* openssl support – **yes**
* http2 protocol – **yes** (optional but nice)
* coroutine curl / mysqlnd / postgres – answer **yes** for anything you intend to use

Finally, add the extensions to your *CLI* `php.ini` (replace paths with your PHP version):

```bash
echo "extension=openswoole.so" | sudo tee /etc/php/8.3/cli/conf.d/99-zealphp-openswoole.ini
echo "extension=uopz.so"       | sudo tee -a /etc/php/8.3/cli/conf.d/99-zealphp-openswoole.ini

# Optional but handy while templating
echo "short_open_tag=on"       | sudo tee -a /etc/php/8.3/cli/conf.d/99-zealphp-openswoole.ini
```

Verify the installation:

```bash
php -m | grep -E "(openswoole|uopz)"
```

Both names must appear.

---

## 2. Create a new project

```bash
composer create-project --stability=dev sibidharan/zealphp-project my-project
cd my-project
composer update        # pulls the latest ZealPHP core
```

---

## 3. Start the server

```bash
php app.php

# ➜ ZealPHP server running at http://0.0.0.0:8080 with 8 routes
```

Navigate to the URL and you will see the default landing page rendered by ZealPHP.

---

## 4. Running as a daemon (production)

When you are ready to deploy, pass OpenSwoole configuration options into `App::run()`:

```php
$app->run([
    'daemonize' => true,       // detach from terminal
    'worker_num' => 8,         // number of workers
    'task_worker_num' => 8,    // background task workers
]);
```

You can then create a `systemd` service or run the script behind Nginx acting as a reverse proxy.

---

## 5. IDE helpers

For excellent auto-completion, install the [openswoole/ide-helper](https://github.com/openswoole/ide-helper) package and add it to *intelephense* include paths:

```jsonc
"intelephense.environment.includePaths": [
  "vendor/openswoole/ide-helper"
]
```

---

Next up: [Routing Fundamentals →](02-routing.md)

