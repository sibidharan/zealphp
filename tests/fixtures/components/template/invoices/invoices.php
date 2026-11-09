<?php use ZealPHP\App; ?>
<h1>Invoices</h1>
<?= App::component('card', ['title' => 'Open', 'slot' => App::component('total', ['invoices' => $invoices])]) ?>
<table><?php foreach ($invoices as $invoice): ?><?= App::component('row', ['invoice' => $invoice]) ?><?php endforeach; ?></table>
