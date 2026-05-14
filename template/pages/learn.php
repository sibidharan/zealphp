<?php use ZealPHP\App; $active = $active ?? 'learn'; ?>
<div class="learn-layout">
  <?php App::render('/_learn_sidebar', ['active' => $active]); ?>
  <article class="lesson-content">
    <?php App::render('/components/_lesson_header', [
      'number'   => 1,
      'title'    => 'Quick Start',
      'subtitle' => 'What ZealPHP is, in one paragraph — and why you would build with it.',
      'next'     => ['slug' => 'learn/create-app', 'title' => 'Create a ZealPHP App'],
    ]); ?>
    <p>Lesson content coming in milestone 5.</p>
  </article>
</div>
