<?php

require_once __DIR__ . '/vendor/autoload.php';

date_default_timezone_set('Asia/Kolkata');
use OpenSwoole\Core\Psr\Response;
use OpenSwoole\Coroutine as co;
use OpenSwoole\Coroutine\Channel;
use ZealPHP\App;
use ZealPHP\G;

use function ZealPHP\elog;
use function ZealPHP\response_add_header;
use function ZealPHP\response_set_status;
use function ZealPHP\zlog;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

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

App::superglobals(true);

$app = App::init('0.0.0.0', 8080);
$app->addMiddleware(new AuthenticationMiddleware());
$app->addMiddleware(new ValidationMiddleware());
elog("Middleware added");
# Route for /phpinfo 
$app->route('/phpinfo', function() {
    //Loads template from app/phpinfo.php since PHP_SELF is /app.php
    App::render('phpinfo');
});

$app->route('/json', function($request) {
    // echo "<h1>Test</h1>";
    return $_SESSION;
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
        sleep(3);
        $channel->push('Hello, Coroutine 1!');
    });
    go(function() use ($channel) {
        sleep(3);
        $channel->push('Hello, Coroutine! 2');
    });
    go(function() use ($channel) {
        sleep(1);
        $channel->push('Hello, Coroutine! 3');
    });
    go(function() use ($channel) {
        sleep(2);
        $channel->push('Hello, Coroutine! 4');
    });
    go(function() use ($channel) {
        sleep(3);
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

    return $_SERVER;
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
    echo $_GET[$key] ?? null;
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


$app->run([
    'task_worker_num' => 8
]);