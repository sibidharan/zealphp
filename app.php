<?php

require_once __DIR__ . '/vendor/autoload.php';

date_default_timezone_set('Asia/Kolkata');
use OpenSwoole\Core\Psr\Response;
use OpenSwoole\Coroutine as co;
use OpenSwoole\Coroutine\Channel;
use ZealPHP\App;
use ZealPHP\G;

use function ZealPHP\bench_mode_enabled;
use function ZealPHP\env_flag;
use function ZealPHP\elog;
use function ZealPHP\response_add_header;
use function ZealPHP\response_set_status;
use function ZealPHP\zlog;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\Middleware\CorsMiddleware;
use ZealPHP\Middleware\CompressionMiddleware;
use ZealPHP\Middleware\ETagMiddleware;
use ZealPHP\Middleware\RangeMiddleware;
use ZealPHP\Store;
use ZealPHP\Counter;

class AuthenticationMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        elog("AuthenticationMiddleware: process()");
        $g = G::instance();
        $g->session['test'] = 'test';
        return $handler->handle($request);
        // return new Response('Forbidden', 403, 'success', ['Content-Type' => 'text/plain']);
    }
}

class ValidationMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        elog("Validation: process()");
        $g = G::instance();
        ob_start();
        print_r($request->getQueryParams());
        $data = ob_get_clean();
        // elog($data, "validate");;
        $g->session['validate'] = 'test';
        return $handler->handle($request);
    }
}

App::superglobals(false);
$benchMode = bench_mode_enabled();
$demoMiddleware = env_flag('ZEALPHP_DEMO_MIDDLEWARE', false);
$compressionMiddleware = env_flag('ZEALPHP_COMPRESSION_MIDDLEWARE', false);

$envInt = static function (string $name, int $default, int $min = 1): int {
    $value = getenv($name);
    if ($value === false || $value === '') {
        return $default;
    }

    return max($min, (int) $value);
};

$appPort = $envInt('ZEALPHP_PORT', 8080);
$app = App::init(
    getenv('ZEALPHP_HOST') ?: '0.0.0.0',
    $appPort
);
if (!$benchMode) {
    $app->addMiddleware(new CorsMiddleware());         // outermost — handles preflight, adds Allow-Origin
    $app->addMiddleware(new ETagMiddleware());         // generates ETag, returns 304 on cache hit
    $app->addMiddleware(new RangeMiddleware());       // Range / 206 Partial Content (RFC 7233)
    if ($compressionMiddleware) {
        $app->addMiddleware(new CompressionMiddleware());
    }
    // Demo-only middleware. Enable with ZEALPHP_DEMO_MIDDLEWARE=1.
    if ($demoMiddleware) {
        $app->addMiddleware(new AuthenticationMiddleware());
        $app->addMiddleware(new ValidationMiddleware());
    }
    elog("Core middleware added");
}
# Route for /phpinfo 
$app->route('/phpinfo', function() {
    //Loads template from app/phpinfo.php since PHP_SELF is /app.php
    App::render('phpinfo');
});

$app->route('/json', function($request) {
    return G::instance()->session;
});

$app->route('/raw/bench', ['raw' => true], function() {
    return 'You requested: bench';
});

$app->route('/bench/template', function() {
    App::render('/bench_page', [
        'title' => 'ZealPHP Benchmark',
        'items' => [
            ['name' => 'Routing', 'desc' => 'Flask-style routes'],
            ['name' => 'Streaming', 'desc' => 'SSR via yield'],
            ['name' => 'WebSocket', 'desc' => 'Built-in real-time'],
            ['name' => 'Store', 'desc' => 'Shared memory'],
            ['name' => 'Coroutines', 'desc' => 'go() + Channel'],
        ],
    ]);
});

$app->route('/stream_test',[
    'methods' => ['GET', 'PUT']
], function($request) {
        // Original data
    $originalData = "ZealPHP is awesome!!!";
    // $stream = \OpenSwoole\Core\Psr\Stream::streamFor("Test Data");
    // elog($stream->read(10), "streamio_psr");
    $stream = fopen('php://memory', 'r+');
    $resource = $originalData;
    if ($resource !== '') {
        fwrite($stream, (string) $resource);
        fseek($stream, 0);
    }
    $data = stream_get_contents($stream);
    elog("Stream Data: $data");
    // Step 1: Base64 Encoding
    $stream = fopen('php://memory', 'w+');
    $encodedStream = fopen('php://filter/write=convert.base64-encode/resource=php://memory', 'w+');
    fwrite($encodedStream, $originalData);
    rewind($encodedStream);
    $base64Encoded = stream_get_contents($encodedStream);
    fseek($encodedStream, 0);
    fclose($encodedStream);
    elog("Base64 Encoded:\n$base64Encoded\n");

    // Step 2: Base64 Decoding
    rewind($stream); // Reset the stream position
    $decodedStream = fopen('php://filter/read=convert.base64-decode/resource=php://memory', 'r');
    $decodedStream = fopen('php://filter/read=convert.base64-decode/resource=php://memory', 'w+');
    fwrite($decodedStream, $base64Encoded);
    rewind($decodedStream);
    $decodedData = stream_get_contents($decodedStream);
    elog("Base64 Decoded:\n$decodedData\n");
    // Close the streams
    fclose($stream);
    fclose($decodedStream);

    $file = file_get_contents('php://input');
    elog("php://input file_get_contents(): ".$file);

    return new Response('Stream Test: '.$file, 200, 'success', ['Content-Type' => 'text/plain']);
});


$app->route('/co', function() {
    $channel = new Channel(5);
    go(function() use ($channel) {
        co::sleep(3);
        $channel->push('Hello, Coroutine 1!');
    });
    go(function() use ($channel) {
        co::sleep(3);
        $channel->push('Hello, Coroutine! 2');
    });
    go(function() use ($channel) {
        co::sleep(1);
        $channel->push('Hello, Coroutine! 3');
    });
    go(function() use ($channel) {
        co::sleep(2);
        $channel->push('Hello, Coroutine! 4');
    });
    go(function() use ($channel) {
        co::sleep(3);
        $channel->push('Hello, Coroutine 5!');
    });
    $results = [];
    for ($i = 0; $i < 5; $i++) {
        $results[] = $channel->pop();
    }
    echo "<pre>";
    print_r($results);
    echo "</pre>";
});

// $app->route('/home', function() {
//     echo "<h1>This is home override</h1>";
// });

$app->route('/quiz/{page}', function($page) {
    echo "<h1>This is quiz: $page</h1>";
});

$app->route('/quiz/{page}/{tab}/{nwe}', function($nwe, $tab, $page) {

    echo "<h1>This is quiz: $page tab=$tab</h1>";
});

// $app->route('/quiz/{page}/{tab}/{id}', function($page, $tab, $id) {
//     echo "<h1>This is quiz: $page tab=$tab id=$id</h1>";
// });

// $app->route('/hello/{name}', function($name, $self) {
//     echo "<h1>Hello, $self->get $name!</h1>";
// });

$app->route('/sessleak', function(){

});

$app->route("/suglobal/{name}", [
    'methods' => ['GET', 'POST']
],function($name) {
    response_add_header('X-Prototype',  'buffer');
    response_set_status(202);
    // $g = G::instance();
    if(App::$superglobals){
        if (isset($GLOBALS[$name])) {
            print_r($GLOBALS[$name]);
        } else{
            echo "Unknown superglobal $name";
        }
    } else {
        $g = G::instance();
        if (isset($g->$name)) {
            print_r($g->$name);
        } else{
            echo "Unknown global $name";
        }
    }
});

$app->route("/header", [
    'methods' => ['GET', 'POST']
],function() {
    header('Content-Type: text/plain');
    header('X-Test: foo');
    setcookie('test', 'test');
    header("Location: https://example.com");

    return G::instance()->server;
});

$app->route("/exittest", [
    'methods' => ['GET', 'POST']
],function() {
    echo "Exiting...";
    exit(1);
});

$app->route("/coglobal/set/session", [
    'methods' => ['GET', 'POST']
],function($name) {
    $G = G::instance();
    $G->session['name'] = $name;
    return new Response('Session set', 300, 'success', ['Content-Type' => 'text/plain', 'X-Test' => 'test']);
});

$app->route("/coglobal/get/{name}", [
    'methods' => ['GET', 'POST']
],function($name) {
    echo G::get('session')['name'];
});

$app->route('/user/{id}/post/{postId}',[
    'methods' => ['GET', 'POST']
], function($id, $postId) {
    echo "<h1>User $id, Post $postId</h1>";
});

$app->nsRoute('watch', '/get/{key}', function($key){
    echo G::instance()->get[$key] ?? null;
});

// patternRoute
// Matches any URL starting with /raw/
$app->patternRoute('/raw/(?P<rest>.*)', ['methods' => ['GET']], function($rest) {
    echo "You requested: $rest";
});

# Override Implicit Rules
// $app->nsRoute('api', '{name}', function($name) {
//     echo "<h1>Namespace Route Override, $name!</h1>";
// });


$settings = [
    'task_worker_num' => $envInt('ZEALPHP_TASK_WORKERS', 8, 0),
];

$settings['http_compression'] = env_flag('ZEALPHP_HTTP_COMPRESSION', !$compressionMiddleware);

$workerNum = getenv('ZEALPHP_WORKERS');
if ($workerNum !== false && $workerNum !== '') {
    $settings['worker_num'] = max(1, (int) $workerNum);
}

foreach ([
    'ZEALPHP_MAX_CONN'      => 'max_conn',
    'ZEALPHP_MAX_COROUTINE' => 'max_coroutine',
    'ZEALPHP_BACKLOG'       => 'backlog',
    'ZEALPHP_REACTOR_NUM'   => 'reactor_num',
] as $envName => $settingName) {
    $settingValue = getenv($envName);
    if ($settingValue !== false && $settingValue !== '') {
        $settings[$settingName] = max(1, (int) $settingValue);
    }
}

$pidFile = getenv('ZEALPHP_PID_FILE');
if ($pidFile === false || trim((string) $pidFile) === '') {
    $logDir = getenv('ZEALPHP_LOG_DIR');
    if ($logDir === false || trim((string) $logDir) === '') {
        $logDir = '/tmp/zealphp';
    }
    $pidFile = rtrim(trim((string) $logDir), '/') . '/zealphp_' . $appPort . '.pid';
}
$pidFile = trim((string) $pidFile);
if ($pidFile !== '') {
    $pidDir = dirname($pidFile);
    if ($pidDir !== '.' && !is_dir($pidDir)) {
        @mkdir($pidDir, 0775, true);
    }
    $settings['pid_file'] = $pidFile;
}

$daemonize = env_flag('ZEALPHP_DAEMONIZE', false);
if ($daemonize) {
    $settings['daemonize'] = true;
}

$serverLogFile = getenv('ZEALPHP_SERVER_LOG_FILE');
if ($serverLogFile === false || $serverLogFile === '') {
    if ($daemonize) {
        $logDir = getenv('ZEALPHP_LOG_DIR');
        if ($logDir === false || trim((string) $logDir) === '') {
            $logDir = '/tmp/zealphp';
        }
        $serverLogFile = rtrim(trim((string) $logDir), '/') . '/server.log';
    }
}
if ($serverLogFile !== false && trim((string) $serverLogFile) !== '') {
    $serverLogFile = trim((string) $serverLogFile);
    $serverLogDir = dirname($serverLogFile);
    if ($serverLogDir !== '.' && !is_dir($serverLogDir)) {
        @mkdir($serverLogDir, 0775, true);
    }
    $settings['log_file'] = $serverLogFile;
}

$app->run($settings);
