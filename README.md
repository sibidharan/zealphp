# Zeal PHP - an opensource PHP framework that runs on OpenSwoole

A powerful light weight opensource alternative to NextJS - that uses OpenSwoole's Coroutine Caps to do everything NextJS can and do much more. 

Features:
1. Dynamic HTML Streaming with APIs and Sockets
2. Parallel Data Fetching and Processing (Use go() to run async coroutine)
3. Dynamic Routing Tree with Implicit Routes for Public and API 
4. Programmable and Injectable Routes for Authentication
5. Dynamic and Nested Templating and HTML Rendering
6. Workers, Tasks and Processes
7. All PHP Superglobals are constructed per request

# Get Started

## 1. Install OpenSwoole - PHP Server with Asynchronous IO
We will install some dependencies before configuring OpenSwoole. 

```
#!/bin/bash

# Install the GCC Compiler
sudo apt install gcc

# Required for PECL installation and manual OpenSwoole compilation
sudo apt install php-dev

# Main requirements for OpenSwoole and useful packages
sudo apt install openssl
sudo apt install libssl-dev
sudo apt install curl
sudo apt install libcurl4-openssl-dev
sudo apt install libpcre3-dev
sudo apt install build-essential
```

Now lets install OpenSwoole. Compared with other async programming frameworks or software such as Nginx, Tornado, Node.js, Open Swoole is a complete async solution that has built-in support for async programming via fibers/coroutines, a range of multi-threaded I/O modules (HTTP Server, WebSockets, GRPC, TaskWorkers, Process Pools) and support for popular PHP clients like PDO for MySQL, Redis and CURL.

ZealPHP uses OpenSwoole and offers a Web Development Framework that offers APIs, Routes, Sessions, Superglobals, Impicit Routing, Templating, Dynamic Injection, Dynamic HTML Streaming and much more that brings modern web development paradigms for your favorite language. 

Installation of OpenSwoole will take a while, grab a cup of coffee ☕

```
$ sudo pecl install openswoole-22.1.2
```
Now the building process will start, and within seconds you need to answer a few questions as follows for the compiling to begin. Compilation will take sometime depending on your CPU.

```
enable coroutine sockets? [no] : yes
enable openssl support? [no] : yes
enable http2 protocol? [no] : yes
enable coroutine mysqlnd? [no] : yes
enable coroutine curl? [no] : yes
enable coroutine postgres? [no] : no
```

After a lot of console messages, the build process should end with this messages

```
Build process completed successfully
Installing '/usr/lib/php/20230831/openswoole.so'
Installing '/usr/include/php/20230831/ext/openswoole/config.h'
Installing '/usr/include/php/20230831/ext/openswoole/php_openswoole.h'
install ok: channel://pecl.php.net/openswoole-22.1.2
configuration option "php_ini" is not set to php.ini location
You should add "extension=openswoole.so" to php.ini
```

According to your PHP version, you simply need to add `extension=openswoole.so` in your php.ini file. I am using PHP 8.3 in this case.

```
cd /etc/php/8.3/cli/conf.d
touch 00-openswoole.ini
echo "extension=openswoole.so" | sudo tee -a /etc/php/8.3/cli/conf.d/00-openswoole.ini
```

We are good to go. Let's check if the setup is working. 

```
$ php -m | grep openswoole
openswoole
```

If it prints `openswoole` then the module is loaded and is ready to go. 

## 2. Installing Composer (skip if already done)

We are going to rely on `composer`. So install it if not already done.
```
sudo apt install composer 
```
Now lets get started. 

## 3. Getting started with ZealPHP Framework

To create a new project from our go-to template. Replace `my-project` with your project name.

```
composer create-project --stability=dev sibidharan/zealphp-project my-project 
```

With composer installed, lets run our ZealPHP Project

```
cd my-project
php app.php 
Including route file: /var/labsstorage/home/sibidharan/test/my-project/route/info.php
ZealPHP server running at http://0.0.0.0:8080 with 8 routes
```

We are good to go. You can checkout https://github.com/sibidharan/zealphp for more development examples. We will write documentation for the Classes and usage very soon. 


# Common Errors

### 1. When openswoole is not installed and configured

```
└❯ php app.php 
PHP Fatal error:  Uncaught Error: Class "Swoole\HTTP\Server" not found in /var/labsstorage/home/sibidharan/zealphp/src/App.php:322
Stack trace:
#0 /var/labsstorage/home/sibidharan/zealphp/app.php(100): ZealPHP\App->run()
#1 {main}
  thrown in /var/labsstorage/home/sibidharan/zealphp/src/App.php on line 322
```

### Summary steps needed to configure ZealPHP Project

1. Install OpenSwoole using pecl
    `sudo pecl install openswoole-22.1.2`
    - Enable curl coroutines and coroutine sockets, if curl.h error throws, `sudo apt install libcurl4-openssl-dev`

2. Add the extension to php.ini (cli prefered)
    
3. Check if openswoole is configured properly
    ` php -m | grep swoole `

Uptil this `setup.sh` can do it for you. 

4. Run 
    `php app.php`
    >>> ZealPHP server running at http://0.0.0.0:9501

# Configure IDE for Smooth Development Experience

5. Add `swoole` to Intelephense stubs 

6. Make sure you have included the openswoole ide-helper https://github.com/openswoole/ide-helper in the includePaths:

"intelephense.environment.includePaths": [
  "vendor/openswoole/ide-helper"
]

Important:
1. Do not close PHP tags in file if not using HTML 
2. Use coroutines with caution - more testing needed to see if any data leak happens and validate SessionManager implementation
