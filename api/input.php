<?
$input = function() {
    $file = file_get_contents('php://input');
    $this->response($this->json([
        'stream_test' => 'Stream Test: ' . $file,
    ]), 200);
};
