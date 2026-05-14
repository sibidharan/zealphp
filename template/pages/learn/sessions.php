<?php use ZealPHP\App; $active = $active ?? 'learn/sessions'; ?>
<div class="learn-layout">
  <?php App::render('/_learn_sidebar', ['active' => $active]); ?>
  <article class="lesson-content">
    <?php App::render('/components/_lesson_header', [
      'number'   => 6,
      'title'    => 'Sessions & Auth',
      'subtitle' => 'Placeholder — content lands in milestones 5, 6, 8.',
      'prev'     => ['slug' => 'learn/routing', 'title' => 'Routing'],
      'next'     => ['slug' => 'learn/htmx', 'title' => 'Add htmx'],
    ]); ?>
    <p>Lesson content coming soon.</p>
  </article>
</div>
