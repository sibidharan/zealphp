<?php
/**
 * Opening half of the minimal /api/learn/demo/* page: <head> and the
 * back-link nav. learn_shell.php closes it around a body; the streaming
 * render demo yields it first and its rows after.
 *
 * @var string $title
 * @var string $note  optional text after the title in the nav
 */
use function ZealPHP\e;

$note ??= '';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e($title) ?> · ZealPHP Learn</title>
  <link rel="stylesheet" href="/css/learn.css">
  <link rel="stylesheet" href="/css/learn-demo-shell.css">
</head>
<body>
  <nav><a href="/learn/components">← Back to Lesson 4</a> · <strong><?= e($title) ?></strong><?= $note !== '' ? ' · ' . e($note) : '' ?></nav>
