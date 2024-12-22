<?php

// Start capturing STDOUT
ob_start(); 

// Capture STDERR by redirecting it to a custom stream
$stderrBuffer = fopen('php://temp', 'r+');

// Example: STDOUT
echo "This is standard output (STDOUT).\n";

// Example: STDERR
fwrite(STDERR, "This is an error message (STDERR).\n");
stream_copy_to_stream(STDERR, $stderrBuffer);  // Redirect STDERR to temp stream

// Get the STDOUT content from the output buffer
$stdoutContent = ob_get_clean();

// Get the content from the stderrBuffer
rewind($stderrBuffer);
$stderrContent = stream_get_contents($stderrBuffer);

// Display the results
echo "Captured STDOUT:\n" . $stdoutContent;
echo "\nCaptured STDERR:\n" . $stderrContent;

// Close the custom STDERR stream
fclose($stderrBuffer);

?>
