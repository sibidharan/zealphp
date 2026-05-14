<?php use ZealPHP\App; $active = $active ?? 'learn/deployment'; ?>
<div class="learn-layout">
  <?php App::render('/_learn_sidebar', ['active' => $active]); ?>
  <article class="lesson-content">
    <?php App::render('/components/_lesson_header', [
      'number'   => 11,
      'title'    => 'Deployment',
      'subtitle' => 'Placeholder — content lands in milestones 5, 6, 8.',
      'prev'     => ['slug' => 'learn/async', 'title' => 'Async & Coroutines'],
      'next'     => ['slug' => 'learn/philosophy', 'title' => 'Philosophy'],
    ]); ?>
    <p>Lesson content coming soon.</p>
  </article>
</div>
