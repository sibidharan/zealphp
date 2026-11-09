<?php use function ZealPHP\e; ?><p>total <?= e(array_sum(array_column($invoices, 'amount'))) ?></p>
