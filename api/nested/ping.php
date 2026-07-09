<?php
// #485 integration fixture — 1 directory level (control): /api/nested/ping
$ping = function () {
    return ['ok' => true, 'levels' => 1];
};
