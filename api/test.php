<?
${basename(__FILE__, '.php')} = function () {
    $this->response($this->json($_SERVER), 200);
};