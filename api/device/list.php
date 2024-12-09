<?
${basename(__FILE__, '.php')} = function () {
    $this->response($this->json(['msg'=>'working']), 200);
};