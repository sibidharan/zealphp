<?php
// #446 fixture — a child component with its own App::fragment() region.
// Rendered standalone OR nested inside a parent's fragment closure, its 'cr'
// region must run inline either way (the child passes no fragment selector).
echo 'CB|';
\ZealPHP\App::fragment('cr', function () { echo 'CRI'; });
echo '|CA';
