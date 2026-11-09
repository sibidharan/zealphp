<?php
/**
 * The minimal page around an /api/learn/demo/* response.
 *
 * @var string $title
 * @var \ZealPHP\SafeHtml $slot the page body
 */
use ZealPHP\App;
?>
<?= App::component('/demos/components/learn_head', ['title' => $title]) ?>
  <?= $slot ?>
</body>
</html>
