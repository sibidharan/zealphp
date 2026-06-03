<?php
// Sibling api namespace with NO App::when scope — proves when() is path-scoped:
// there is no X-Api-Secured header here (only /api/secured/* gets it).
$list = function () {
    return ['ok' => true, 'api' => 'open/list', 'note' => 'No X-Api-Secured — different namespace'];
};
