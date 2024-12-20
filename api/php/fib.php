<?php

$fib = function(){
    $fibonacci = function($n) {
        if ($n <= 1) {
            return $n;
        }
        $a = 0;
        $b = 1;
        for ($i = 2; $i <= $n; $i++) {
            $temp = $a + $b;
            $a = $b;
            $b = $temp;
        }
        return $b;
    };
    $start = microtime(true);
    echo $fibonacci(38);
    $end = microtime(true);
    echo "\nTime: " . number_format($end - $start, 10) . " seconds\n";
    return 201;
};

