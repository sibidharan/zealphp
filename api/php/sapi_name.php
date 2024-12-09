<?
${basename(__FILE__, '.php')} = function () {
    $this->response($this->json(['msg'=>php_sapi_name()]), 200);
};