<?php

$get = function() {
    $this->response($this->json(['msg'=>__FILE__]), 200);
};