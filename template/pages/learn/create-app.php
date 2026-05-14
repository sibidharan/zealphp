<?php use ZealPHP\App; $active = $active ?? 'learn/create-app'; ?>
<div class="learn-layout">
  <?php App::render('/_learn_sidebar', ['active' => $active]); ?>
  <article class="lesson-content">
    <?php App::render('/components/_lesson_header', [
      'number'   => 2,
      'title'    => 'Create a ZealPHP App',
      'subtitle' => 'Placeholder — content lands in milestones 5, 6, 8.',
      'prev'     => ['slug' => 'learn', 'title' => 'Quick Start'],
      'next'     => ['slug' => 'learn/first-page', 'title' => 'Your First Page'],
    ]); ?>
    <p>Lesson content coming soon.</p>
  </article>
</div>
