<?php
/**
 * The answer to a concept check (_concept_check.php posts to
 * /api/learn/demo/check, which returns this).
 *
 * @var bool   $correct
 * @var string $explain the lesson author's explanation, already reduced to
 *                      inert tags by Demo::concept_check_explanation()
 */
use ZealPHP\App;
?>
<?= App::component('callout', [
    'variant' => $correct ? 'success' : 'warn',
    'title'   => $correct ? 'Correct!' : 'Not quite',
    'body'    => '<p>' . $explain . '</p>',
]) ?>
