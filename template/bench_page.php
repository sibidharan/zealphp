<?php
$title ??= 'ZealPHP Benchmark';
$items ??= [];
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title><?= htmlspecialchars($title) ?></title></head>
<body>
<h1><?= htmlspecialchars($title) ?></h1>
<ul>
<?php foreach ($items as $item): ?>
  <li><strong><?= htmlspecialchars($item['name']) ?></strong> — <?= htmlspecialchars($item['desc']) ?></li>
<?php endforeach; ?>
</ul>
<p>Rendered at <?= date('c') ?></p>
</body>
</html>
