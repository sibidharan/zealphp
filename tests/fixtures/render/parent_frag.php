<?php
// #446 fixture — a parent page whose 'want' fragment closure does a NESTED
// render of child_frag (passing NO fragment arg). The nested child must run its
// own 'cr' region inline, not inherit the parent's active 'want' selector.
echo 'HEAD|';
\ZealPHP\App::fragment('want', function () {
    echo 'W[' . \ZealPHP\App::renderToString('child_frag', [], 'tests/fixtures/render') . ']';
});
echo '|TAIL';
