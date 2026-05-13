<?php use ZealPHP\App; ?>

<section class="section">
  <div class="container">
    <h1 class="section-title">Getting Started</h1>
    <p class="section-desc">
      Use the starter project when you want the full ZealPHP app tree, or boot the framework
      repository directly from its project root.
    </p>

    <h2 style="margin:2rem 0 .5rem">Starter project</h2>
    <p class="section-desc">This creates the app tree in <code>~/zealphp-project</code> and starts the server from there.</p>
    <?php App::render('/components/_code', [
      'label' => 'composer create-project sibidharan/zealphp-project:^0.1.1 ~/zealphp-project',
      'lang' => 'bash',
      'code' => <<<'BASHCODE'
composer create-project sibidharan/zealphp-project:^0.1.1 ~/zealphp-project
cd ~/zealphp-project
php app.php
BASHCODE
    ]); ?>

    <h2 style="margin:2rem 0 .5rem">Framework repo</h2>
    <p class="section-desc">Clone the framework repo, then start it from the project root.</p>
    <?php App::render('/components/_code', [
      'label' => 'Framework repo bootstrap',
      'lang' => 'bash',
      'code' => <<<'BASHCODE'
git clone https://github.com/sibidharan/zealphp.git ~/zealphp
cd ~/zealphp
php app.php
BASHCODE
    ]); ?>

    <div class="callout info">
      The project template is the clean new-app path. The framework repository itself still starts with <code>php app.php</code>.
    </div>
  </div>
</section>
