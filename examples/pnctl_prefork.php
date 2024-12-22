
<?php

// Check if pcntl extension is available
if (!function_exists('pcntl_fork')) {
    die('pcntl extension is not available. Please install the pcntl extension.');
}

// Create named pipes (FIFOs) for stdout and stderr
$stdoutFifo = '/tmp/child_stdout.fifo';
$stderrFifo = '/tmp/child_stderr.fifo';

// Create the named pipes (FIFOs) if they don't exist
if (!file_exists($stdoutFifo)) {
    if (false === posix_mkfifo($stdoutFifo, 0666)) {
        die("Failed to create FIFO for stdout.\n");
    }
}
if (!file_exists($stderrFifo)) {
    if (false === posix_mkfifo($stderrFifo, 0666)) {
        die("Failed to create FIFO for stderr.\n");
    }
}

// Fork the process
$pid = pcntl_fork();

if ($pid == -1) {
    // Handle error
    die("Failed to fork\n");
} elseif ($pid === 0) {
    // Child process

    // Open the FIFO files for writing
    $stdout = fopen($stdoutFifo, 'w');
    $stderr = fopen($stderrFifo, 'w');

    // Redirect the child's output to the FIFOs
    fwrite($stdout, "This is stdout from the child\n");
    fwrite($stderr, "This is stderr from the child\n");

    // Simulate some work
    // sleep(2);

    // Close the FIFOs in the child
    fclose($stdout);
    fclose($stderr);

    // Exit the child process
    exit(0);
} else {
    // Parent process

    // Open the FIFOs for reading
    $stdout = fopen($stdoutFifo, 'r');
    $stderr = fopen($stderrFifo, 'r');

    // Wait for the child process to finish
    pcntl_waitpid($pid, $status);

    // Read and output the child's stdout and stderr
    echo "Child stdout:\n";
    while ($line = fgets($stdout)) {
        echo $line;
    }

    echo "\nChild stderr:\n";
    while ($line = fgets($stderr)) {
        echo $line;
    }

    // Close the FIFOs in the parent
    fclose($stdout);
    fclose($stderr);

    // Clean up by removing the FIFOs
    unlink($stdoutFifo);
    unlink($stderrFifo);
}

