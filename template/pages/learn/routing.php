<?php use ZealPHP\App; $active = $active ?? 'learn/routing'; ?>
<div class="learn-layout">
  <?php App::render('/_learn_sidebar', ['active' => $active]); ?>
  <article class="lesson-content">
    <?php App::render('/components/_lesson_header', [
      'number'   => 5,
      'title'    => 'Routing',
      'subtitle' => 'Placeholder — content lands in milestones 5, 6, 8.',
      'prev'     => ['slug' => 'learn/components', 'title' => 'Components'],
      'next'     => ['slug' => 'learn/sessions', 'title' => 'Sessions & Auth'],
    ]); ?>
    <p>Lesson content coming soon.</p>
  </article>
</div>
