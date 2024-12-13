<?php
namespace ZealPHP;
use OpenSwoole\Coroutine as co;
class G
{
    public static function init()
    {
        // Initialize the global context
        $context = co::getContext();
        $context['session'] = [];
        $context['get'] = [];
        $context['post'] = [];
        $context['files'] = [];
        $context['cookie'] = [];
        $context['server'] = [];
        $context['env'] = [];

        // Initialize the global session
        $_GET = &$context['get'];
        $_POST = &$context['post'];
        $_FILES = &$context['files'];
        $_COOKIE = &$context['cookie'];
        $_SERVER = &$context['server'];
        $_ENV = &$context['env'];
        $_REQUEST = &$context['request'];
        $_SESSION = &$context['session'];
    }

    // Set is used to save a new value under the context
    public static function set(string $key, mixed $value)
    {
        co::getContext()[$key] = $value;
    }

    // Navigate the coroutine tree and search for the requested key
    public static function get(string $key, mixed $default = null): mixed
    {
        // Get the current coroutine ID
        $cid = co::getCid();

        do
        {
            /*
             * Get the context object using the current coroutine
             * ID and check if our key exists, looping through the
             * coroutine tree if we are deep inside sub coroutines.
             */
            if(isset(co::getContext($cid)[$key]))
            {
                return co::getContext($cid)[$key];
            }

            // We may be inside a child coroutine, let's check the parent ID for a context
            $cid = co::getPcid($cid);

        } while ($cid !== -1 && $cid !== false);

        // The requested context variable and value could not be found
        return $default ?? throw new \InvalidArgumentException(
            "Could not find `{$key}` in current coroutine context."
            );
    }
}

// // Our HTTP server instance
// $server = new Swoole\HTTP\Server("127.0.0.1", 9501);

// // ...

// // The request event handler callback
// $server->on('Request', function($request, $response)
// {
//     /*
//      * At the start of every new request, setup global
//      * request variables using Swoole server methods.
//      */
//    ContextManager::set('_GET', (array)$request->get);
//    ContextManager::set('_POST', (array)$request->post);
//    ContextManager::set('_FILES', (array)$request->files);
//    ContextManager::set('_COOKIE', (array)$request->cookie);

//   // And when you use them
//   echo ContextManager::get('_GET')['foo'] ?? 'bar';

//   // Instead of traditional PHP global variables...
//   echo $_GET['foo'] ?? 'bar';
// });
