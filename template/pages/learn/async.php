<?php use ZealPHP\App; $active = $active ?? 'learn/async'; ?>
<div class="learn-layout">
  <?php App::render('/_learn_sidebar', ['active' => $active]); ?>
  <article class="lesson-content">
    <?php App::render('/components/_lesson_header', [
      'number'   => 10,
      'title'    => 'Async & Coroutines',
      'subtitle' => 'Placeholder — content lands in milestones 5, 6, 8.',
      'prev'     => ['slug' => 'learn/ai-chat', 'title' => 'Add AI Chat'],
      'next'     => ['slug' => 'learn/deployment', 'title' => 'Deployment'],
    ]); ?>
    <p>Lesson content coming soon.</p>
  </article>
</div>
