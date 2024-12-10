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

### Baby steps needed to configure ZealPHP Project

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

#### Configure IDE for Smooth Development Experience

5. Add `swoole` to Intelephense stubs 

6. Make sure you have included the openswoole ide-helper https://github.com/openswoole/ide-helper in the includePaths:

"intelephense.environment.includePaths": [
  "vendor/openswoole/ide-helper"
]

Important:
1. Do not close PHP tags in file if not using HTML 
2. Use coroutines with caution - more testing needed to see if any data leak happens and validate SessionManager implementation
