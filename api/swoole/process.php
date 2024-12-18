<?
use OpenSwoole\Process;
use OpenSwoole\Coroutine as co;
$process = function(){
    // Serialize the task logic as a string
    $taskLogic = function ($data, $worker) {
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

        $worker->exit(1);
    };
    $data = "Sample Data";

    // Pass logic to workers indirectly
    $worker = new Process(function ($worker) use ($taskLogic, $data) {
        $taskLogic($data, $worker); // Execute the passed logic
    }, true, SOCK_DGRAM, true);

    // Start the worker
    $worker->start();
    Process::wait(true);
    print_r($worker->read());
    print_r($_SESSION);

};