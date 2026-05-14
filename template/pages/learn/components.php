<?php use ZealPHP\App; $active = $active ?? 'learn/components'; ?>
<div class="learn-layout">
  <?php App::render('/_learn_sidebar', ['active' => $active]); ?>
  <article class="lesson-content">
    <?php App::render('/components/_lesson_header', [
      'number'   => 4,
      'title'    => 'Components',
      'subtitle' => 'Placeholder — content lands in milestones 5, 6, 8.',
      'prev'     => ['slug' => 'learn/first-page', 'title' => 'Your First Page'],
      'next'     => ['slug' => 'learn/routing', 'title' => 'Routing'],
    ]); ?>
    <p>Lesson content coming soon.</p>
  </article>
</div>
