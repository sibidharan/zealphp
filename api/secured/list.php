<?php
// Demo api endpoint under the App::when('/api/secured') scope — it receives the
// X-Api-Secured header with no per-file glue (see route/middleware.php).
$list = function () {
    return ['ok' => true, 'api' => 'secured/list', 'note' => "X-Api-Secured set by App::when('/api/secured')"];
};
