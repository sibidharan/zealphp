<?php
use ZealPHP\App;
$title       ??= 'ZealPHP';
$description ??= 'The async PHP framework built on OpenSwoole.';
$page        ??= 'home';
$active      ??= $page;
?>
<!doctype html>
<html lang="en">
<?php App::render('/_head', compact('title', 'description', 'page')); ?>
<body hx-boost="true" hx-ext="head-support">
<div id="htmx-progress" class="htmx-progress" aria-hidden="true" hx-preserve="true"></div>
<?php App::render('/_nav', ['active' => $active]); ?>
<?php App::render('/components/_banner'); ?>
<main class="page-body">
<?php App::render("/pages/$page", get_defined_vars()); ?>
</main>
<?php App::render('/_footer'); ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js"></script>
</body>
</html>
