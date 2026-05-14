<?php use ZealPHP\App; $active = $active ?? 'learn/first-page'; ?>
<div class="learn-layout">
  <?php App::render('/_learn_sidebar', ['active' => $active]); ?>
  <article class="lesson-content">
    <?php App::render('/components/_lesson_header', [
      'number'   => 3,
      'title'    => 'Your First Page',
      'subtitle' => 'Placeholder — content lands in milestones 5, 6, 8.',
      'prev'     => ['slug' => 'learn/create-app', 'title' => 'Create a ZealPHP App'],
      'next'     => ['slug' => 'learn/components', 'title' => 'Components'],
    ]); ?>
    <p>Lesson content coming soon.</p>
  </article>
</div>
