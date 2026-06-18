<?php
// Fixture for #442 — deliberately lives OUTSIDE tests/fixtures/render/ in a
// SIBLING dir that shares the "render" name prefix. A template name like
// "../render-sibling/leak" passed to App::render(..., 'tests/fixtures/render')
// must NOT resolve here: render() is jailed to the template dir. If this string
// ever surfaces from such a call, the containment check has regressed (the old
// strpos($resolved, self::$cwd) === 0 anchor let sibling-prefix dirs through).
return 'SIBLING-LEAK';
