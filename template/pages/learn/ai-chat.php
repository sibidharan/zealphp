<?php use ZealPHP\App; $active = $active ?? 'learn/ai-chat'; ?>
<div class="learn-layout">
  <?php App::render('/_learn_sidebar', ['active' => $active]); ?>
  <article class="lesson-content">
    <?php App::render('/components/_lesson_header', [
      'number'   => 9,
      'title'    => 'Add AI Chat',
      'subtitle' => 'Placeholder — content lands in milestones 5, 6, 8.',
      'prev'     => ['slug' => 'learn/notes', 'title' => 'Build Personal Notes'],
      'next'     => ['slug' => 'learn/async', 'title' => 'Async & Coroutines'],
    ]); ?>
    <p>Lesson content coming soon.</p>
  </article>
</div>
