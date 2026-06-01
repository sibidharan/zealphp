<?php
// Under App::when('/api/blocked', ['block']) the guard short-circuits with 403
// before dispatch — this handler never runs.
$secret = function () {
    return ['ok' => true, 'note' => 'You should never see this body.'];
};
