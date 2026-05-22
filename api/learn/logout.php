<?php
use ZealPHP\G;
use ZealPHP\Learn\Auth;

${basename(__FILE__, '.php')} = function () {
    session_start();
    $g = G::instance();
    $g->session = [];
    session_destroy();
    // Same htmx-safe redirect as login/register: HX-Redirect (200) for htmx,
    // 302 otherwise — a bare 302 is followed by the XHR and breaks the layout.
    // Returns the user to the page they logged out from (now logged-out view).
    Auth::redirectAfterAuth($g);
};
