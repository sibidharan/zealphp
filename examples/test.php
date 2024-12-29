<?php

// Original data
$originalData = "ZealPHP is awesome!";

// Step 1: Base64 Encoding
$stream = fopen('php://memory', 'w+');
$encodedStream = fopen('php://filter/write=convert.base64-encode/resource=php://memory', 'w+');
fwrite($encodedStream, $originalData);
rewind($encodedStream);
$base64Encoded = stream_get_contents($encodedStream);
fclose($encodedStream);
echo "Base64 Encoded:\n$base64Encoded\n";

// Step 2: Base64 Decoding
rewind($stream); // Reset the stream position
$decodedStream = fopen('php://filter/read=convert.base64-decode/resource=php://memory', 'r');
$decodedStream = fopen('php://filter/read=convert.base64-decode/resource=php://memory', 'w+');
fwrite($decodedStream, $base64Encoded);
rewind($decodedStream);
$decodedData = stream_get_contents($decodedStream);
echo "Base64 Decoded:\n$decodedData\n";
// Close the streams
fclose($stream);
fclose($decodedStream);


