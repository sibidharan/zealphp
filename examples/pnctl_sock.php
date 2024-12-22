<?php

// Ensure uopz extension is available
if (!extension_loaded('uopz')) {
    die("uopz extension is not loaded.\n");
}

// Create two socket pairs: one for STDOUT and one for STDERR
$sockets_stdout = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
$sockets_stderr = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

$pid = pcntl_fork();

if ($pid == -1) {
    die("could not fork\n");

} else if ($pid) {
    // Parent process
    fclose($sockets_stdout[0]);  // Close the parent's read socket for STDOUT
    fclose($sockets_stderr[0]);  // Close the parent's read socket for STDERR

    // Simulate writing to child process
    fwrite($sockets_stdout[1], "Parent: STDOUT message\n");
    fwrite($sockets_stderr[1], "Parent: STDERR message\n");

    // Read the output from child process
    echo "Parent received from child (STDOUT): " . fgets($sockets_stdout[1]);
    echo "Parent received from child (STDERR): " . fgets($sockets_stderr[1]);

    fclose($sockets_stdout[1]);
    fclose($sockets_stderr[1]);

} else {
    // Child process
    fclose($sockets_stdout[1]);  // Close the child's write socket for STDOUT
    fclose($sockets_stderr[1]);  // Close the child's write socket for STDERR
    uopz_undefine('STDOUT');
    uopz_undefine('STDERR');
    // Redefine STDOUT and STDERR to the socket for communication
    uopz_redefine('STDOUT', $sockets_stdout[0]);
    uopz_redefine('STDERR', $sockets_stderr[0]);

    // Simulate the child writing to STDOUT and STDERR
    echo "This is a message to STDOUT from the child.\n";
    fwrite(STDERR, "This is a message to STDERR from the child.\n");

    // Read messages from the parent (via the child's read sockets)
    echo "Child received from parent (STDOUT): " . fgets($sockets_stdout[0]);
    echo "Child received from parent (STDERR): " . fgets($sockets_stderr[0]);

    fclose($sockets_stdout[0]);
    fclose($sockets_stderr[0]);
}

?>
