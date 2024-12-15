# ZealPHP - an opensource PHP framework that runs on OpenSwoole

A powerful light weight opensource alternative to NextJS - that uses OpenSwoole's Async IO to do everything NextJS can and do much more. 

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

After a lot of console messages, the build process should end with these messages

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
touch 99-zealphp-swoole.ini
echo "extension=openswoole.so" | sudo tee -a /etc/php/8.3/cli/conf.d/99-zealphp-swoole.ini

# Enable Short Open Tags for Flexiblity
echo "short_open_tag=on" | sudo tee -a /etc/php/8.3/cli/conf.d/99-zealphp-swoole.ini

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

To create a new project from our go-to template, replace `my-project` with your project name and execute the below composer command. Since this project is in development, use `--stability=dev` until we arrive at a stable version.  

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

## 4. Understanding what is happenning

When you run `app.php` the openswoole server is being run and managed by ZealPHP. It will stay attached to your terminal unless you deamonize, which we wont be doing while development. When moving to production, you can do `$app->run(['daemonize'=>true])` to the run function, which detaches the running script in background. This can be configured to systemctl to run on boot, or to run behind Apache or Nginx. The `run` function can take all OpenSwoole Configuration as mentioned in https://openswoole.com/docs/modules/swoole-server/configuration. Unlike Apache+PHP setup, the functions of Apache like URL Rewriting, Superglobals is replaced by ZealPHP, while OpenSwoole is offering the server. ZealPHP is offering the routing with a very efficient route tree model, which is O(1) in code injection and lookup. On top of that, ZealPHP offers implicit routes that serves the files located under `public` and `api` directories. These routes can be overridden by you.

You can start by defining your routes in `app.php` or under `route` directory which gets imported automatically when you run `app.php`. See the code examples in this project to understand dynamic route injection using `route` folder. This comes handy to maintain large projects, and also maintain a healthy project structure.

You can start writing APIs out of the box without any additional configuration. Look inside `api `folder for more examples. To understand more on how to handle the response, please wait for the documentation or you can checkout https://github.com/sibidharan/zealphp for more development examples. 

Any and all contributions are welcome ❤️

# ZealPHP Design Principals

- Integrating OpenSwoole is a very good move but reaping the full performance of the coroutines and still being able to run an Apache/FPM styled web server with powerful in-memory dynamic nested ZealPHP template render functions while reconstructing superglobals on top of all these comes with a cost. The ZealPHP Server won't enable coroutines for the HTTP server by default, so the main response thread can't run `go()` calls. Instead of the server processes sharing the superglobal memory while running coroutines, we disable coroutines for the server process. To understand what is happening, let's say if a main thread is waiting for an IO or sleeping, the same server worker process will be used to serve another request. This new request will cause the superglobals to overwrite the ones waiting for IO and cause data leak/corruption. We decide to disable coroutines in the main thread, which enables us to support PHP superglobals in an Apache styled way.

- To support incremental adoption of async capabilities and still be able to reap the benefits of coroutines alongside backward compatibility for traditional PHP applications, ZealPHP introduces a function called `coprocess` alias `coproc` that enables you to create a process that has coroutine context, and you can run as many fibers/coroutines inside that process. This function keeps the design pattern clean, and each server worker deals with one request at a time, thus we can use the superglobals while reaping maximum performance on demand.

- `coproc` creates a process which has its own memory space, it is like a fork, so communicating with that process cannot be over superglobals, and the data passing needs to be done delicately. The StdOut is returned. `coproc` is a wrapper to `OpenSwoole\Process` with coroutines enabled and StdIO forwarded. It also makes sharing variables as easy as copying them into the memory before the process forks, then the data is available to the new process. But the limitation here is we cannot pass anything that is not serializable. For example, Database Connection, Sockets, FD, etc. Those unserializable objects have to be reconstructed inside the `coproc`. This design decision may change in the future with us removing support for superglobals with our own implementation. More research and development is needed in this area. (Still in development, documentation will be available later)

- The tradeoff between having superglobals and not using coroutines in the main server process and the implications of security here are still to be researched, and a more stable and sustainable design pattern has to be arrived at. ZealPHP decides to sanitize every request with a default middleware which can be overridden if needed.

- But this is optional. If you want coroutines in the ZealPHP main HTTP server, you just have to turn off superglobals using `App::superglobals(false);` and `Coroutines` will be enabled automatically. To have backward compatibility and to educate users about the design of ZealPHP and OpenSwoole, the default option comes with superglobals enabled. Deliberately requiring users to disable it for using coroutines helps user awareness about what's going on.

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

# Important

1. Do not close PHP tags in file if not using HTML 
2. Use coroutines with caution - more testing needed to see if any data leak happens and validate SessionManager implementation
