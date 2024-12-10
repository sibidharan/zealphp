<?php
use OpenSwoole\Coroutine as co;
use OpenSwoole\Coroutine\Http\Client;
use OpenSwoole\Coroutine\Channel;

co::run(function () {
    // Generate a list of URLs or endpoints. For demo, we'll hit example.com with different query params.
    $urls = [];
    for ($i = 1; $i <= 100; $i++) {
        $urls[] = "http://www.example.com";
    }

    // Create a channel sized to the number of requests
    $channel = new Channel(count($urls));

    // Spawn a coroutine for each URL
    foreach ($urls as $url) {
        go(function () use ($url, $channel) {
           $data = file_get_contents($url);
            // Push the result to the channel
            $channel->push([
                'url'  => $url,
                'data' => substr($data, 0, 50) // Just take the first 50 chars for demonstration
            ]);
        });
    }

    // Collect all results
    $results = [];
    for ($i = 0, $n = count($urls); $i < $n; $i++) {
        $result = $channel->pop();
        if ($result !== false) {
            $results[] = $result;
        }
    }

    // Output as JSON
    header('Content-Type: application/json');
    echo json_encode($results, JSON_PRETTY_PRINT);
});
