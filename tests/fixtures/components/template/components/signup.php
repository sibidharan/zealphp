<input <?= $c->model('email') ?>><?= $c->error('email') ?>
<input <?= $c->model('username') ?>><?= $c->error('username') ?>
<input type="checkbox" <?= $c->model('agree') ?> <?= $c->checked('agree') ?>>
<input type="checkbox" <?= $c->model('tags') ?> value="a" <?= $c->checked('tags', 'a') ?>>
<input type="checkbox" <?= $c->model('tags') ?> value="b" <?= $c->checked('tags', 'b') ?>>
<select <?= $c->model('size', false) ?>><option value="s" <?= $c->selected('size', 's') ?>>S</option></select>
<?php if (!$hideAge): ?><input <?= $c->model("age") ?>><?= $c->error("age") ?><?php endif; ?>
<button <?= $c->action('leave') ?>>Leave</button>
<input type="checkbox" <?= $c->model('newsletter') ?> <?= $c->checked('newsletter') ?>>
<input <?= $c->model('score') ?>><?= $c->error('score') ?>
<textarea <?= $c->model('bio', false) ?>><?= $c->value('bio') ?></textarea><?= $c->error('bio') ?>
<button <?= $c->action('save') ?>>Save</button>
<b class="saved"><?= $saved ? 'yes' : 'no' ?></b><b class="changes"><?= \ZealPHP\e($usernameChanges) ?></b>
