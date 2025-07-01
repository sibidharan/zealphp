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

/**
 * Demo authentication middleware.
 *
 * Looks for a bearer token in the `Authorization` header (or a `token` query
 * parameter) and rejects the request with status 401 when it is missing or
 * invalid.  For the sake of the example we simply compare the token against a
 * static allow-list.
 */
class AuthenticationMiddleware implements MiddlewareInterface
{
    private const VALID_BEARER_TOKENS = [
        'zeal-secret-123',
        'zeal-secret-456',
    ];

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $credential = $this->extractCredential($request);

        if ($credential === null) {
            return $this->unauthorised();
        }

        switch ($credential['type']) {
            case 'bearer':
                if (!in_array($credential['value'], self::VALID_BEARER_TOKENS, true)) {
                    return $this->unauthorised();
                }
                $user = ['token' => $credential['value']];
                break;

            case 'session':
                $sessionUser = $this->resumeSession($credential['value']);
                if ($sessionUser === null) {
                    return $this->unauthorised();
                }
                $user = $sessionUser;
                break;

            default:
                return $this->unauthorised();
        }

        $request = $request->withAttribute('user', $user);
        return $handler->handle($request);
    }

    private function unauthorised(): ResponseInterface
    {
        $payload = json_encode([
            'status'  => 'error',
            'message' => 'Unauthorized',
        ], JSON_UNESCAPED_SLASHES);

        return new Response($payload, 401, 'Unauthorized', [
            'Content-Type' => 'application/json',
        ]);
    }

    private function extractCredential(ServerRequestInterface $request): ?array
    {
        // Bearer via header
        $authHeader = $request->getHeaderLine('Authorization');
        if ($authHeader !== '' && strpos($authHeader, 'Bearer ') === 0) {
            return ['type' => 'bearer', 'value' => substr($authHeader, 7)];
        }

        // Bearer via query param
        $queryParams = $request->getQueryParams();
        if (isset($queryParams['token']) && $queryParams['token'] !== '') {
            return ['type' => 'bearer', 'value' => $queryParams['token']];
        }

        // PHP session cookie
        $cookieParams = $request->getCookieParams();
        if (isset($cookieParams['PHPSESSID']) && trim($cookieParams['PHPSESSID']) !== '') {
            return ['type' => 'session', 'value' => $cookieParams['PHPSESSID']];
        }

        return null;
    }

    private function resumeSession(string $sessionId): ?array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_id($sessionId);
            session_start();
        }

        return isset($_SESSION['user']) && is_array($_SESSION['user']) ? $_SESSION['user'] : null;
    }
}

/**
 * Very small JSON validation middleware.
 *
 * For write operations (POST/PUT/PATCH) with an `application/json` content
 * type it parses the body, checks that the field `name` is present and passes
 * the decoded payload downstream via `withParsedBody()`.
 */
class ValidationMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $method = strtoupper($request->getMethod());

        if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $contentType = $request->getHeaderLine('Content-Type');

            if (strpos($contentType, 'application/json') !== false) {
                $rawBody = (string) $request->getBody();
                $data    = json_decode($rawBody, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    return $this->failed('Malformed JSON payload');
                }

                if (!isset($data['name']) || trim((string) $data['name']) === '') {
                    return $this->failed('Field "name" is required');
                }

                $request = $request->withParsedBody($data);
            }
        }

        return $handler->handle($request);
    }

    private function failed(string $message): ResponseInterface
    {
        $payload = json_encode([
            'status'  => 'error',
            'message' => $message,
        ], JSON_UNESCAPED_SLASHES);

        return new Response($payload, 422, 'Unprocessable Entity', [
            'Content-Type' => 'application/json',
        ]);
    }
}

App::superglobals(false);

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
    $GET = G::instance()->_GET;

    echo "You requested: $rest";
    echo "<br>GET Parameters: ";
    print_r($GET);
});

# Override Implicit Rules
// $app->nsRoute('api', '{name}', function($name) {
//     echo "<h1>Namespace Route Override, $name!</h1>";
// });


$app->run([
    'task_worker_num' => 8
]);
