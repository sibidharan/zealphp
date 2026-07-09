<?php
// #485 integration fixture — 2 directory levels: /api/nested/deep/probe
$probe = function () {
    return ['ok' => true, 'levels' => 2];
};
