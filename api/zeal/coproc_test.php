<?
use OpenSwoole\Coroutine as co;
use function ZealPHP\coproc;

$coproc_test = function($response){
    $data = "Sample Data";
    $data = coproc(function($worker) use ($data){
        echo "Processing data: $data\n";
        $channel = new OpenSwoole\Coroutine\Channel(5);
        go(function() use($channel){
            co::sleep(1);
            print_r($_SERVER);
            $channel->push("Hello, Coroutine 1!");
        });
        go(function() use($channel){
            co::sleep(1);
            print_r($_GET);
            $channel->push("Hello, Coroutine 2!");
        });
        go(function() use($channel){
            co::sleep(1);
            $channel->push("Hello, Coroutine 3!");
        });
        go(function() use($channel){
            co::sleep(1);
            $channel->push("Hello, Coroutine 4!");
        });
        go(function() use($channel){
            co::sleep(1);
            $channel->push("Hello, Coroutine 5!");
        });

        $results = [];
        for ($i = 0; $i < 5; $i++) {
            $results[] = $channel->pop();
        }
        echo "<pre>";
        print_r($results);
        echo "</pre>";
        $_SESSION['test'] = "test";
    });
    echo $data;
    $response->status(202);
};