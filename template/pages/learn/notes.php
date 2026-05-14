<?php use ZealPHP\App; $active = $active ?? 'learn/notes'; ?>
<div class="learn-layout">
  <?php App::render('/_learn_sidebar', ['active' => $active]); ?>
  <article class="lesson-content">
    <?php App::render('/components/_lesson_header', [
      'number'   => 8,
      'title'    => 'Build Personal Notes',
      'subtitle' => 'Placeholder — content lands in milestones 5, 6, 8.',
      'prev'     => ['slug' => 'learn/htmx', 'title' => 'Add htmx'],
      'next'     => ['slug' => 'learn/ai-chat', 'title' => 'Add AI Chat'],
    ]); ?>
    <p>Lesson content coming soon.</p>
  </article>
</div>
