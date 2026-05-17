<?php
// Template fixture: echo only, no explicit return. BC behaviour: App::render()
// must echo the output back so existing public/*.php call sites keep working.
echo 'TPL-ECHOED';
