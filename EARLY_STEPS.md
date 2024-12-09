Baby steps needed to configure ZealPHP Project

1. Install OpenSwoole using pecl
    `sudo pecl install openswoole-22.1.2`
    - Enable curl coroutines and coroutine sockets, if curl.h error throws, `sudo apt install libcurl4-openssl-dev`

2. Add the extension to php.ini (cli prefered)
    
3. Check if openswoole is configured properly
    ` php -m | grep swoole `

4. Run 
    `php public/index.php`
    >>> ZealPHP server running at http://0.0.0.0:9501

5. Add `swoole` to Intelephense stubs 
