<?php
// #485 integration fixture — 3 directory levels: /api/nested/deep/very/probe2
$probe2 = function () {
    return ['ok' => true, 'levels' => 3];
};
