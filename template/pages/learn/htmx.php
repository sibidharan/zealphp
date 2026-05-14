<?php use ZealPHP\App; $active = $active ?? 'learn/htmx'; ?>
<div class="learn-layout">
  <?php App::render('/_learn_sidebar', ['active' => $active]); ?>
  <article class="lesson-content">
    <?php App::render('/components/_lesson_header', [
      'number'   => 7,
      'title'    => 'Add htmx',
      'subtitle' => 'Placeholder — content lands in milestones 5, 6, 8.',
      'prev'     => ['slug' => 'learn/sessions', 'title' => 'Sessions & Auth'],
      'next'     => ['slug' => 'learn/notes', 'title' => 'Build Personal Notes'],
    ]); ?>
    <p>Lesson content coming soon.</p>
  </article>
</div>
