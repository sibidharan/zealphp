<?php use function ZealPHP\e; ?>
<?php if ($saved): ?>
  <p class="lc-success" role="status">Signed up as <strong><?= e($username) ?></strong> (<?= e($email) ?>, <?= e($plan) ?> plan).</p>
  <button <?= $c->action('startOver') ?> class="btn btn-ghost btn-sm lc-btn">Start over</button>
<?php else: ?>
  <label for="<?= e($c->id()) ?>-username">Username <small>validated as you type</small></label>
  <input <?= $c->model('username') ?> autocomplete="off" placeholder="try “admin”">
  <?= $c->error('username') ?>

  <label for="<?= e($c->id()) ?>-email">Email</label>
  <input type="email" <?= $c->model('email') ?> autocomplete="off">
  <?= $c->error('email') ?>

  <label for="<?= e($c->id()) ?>-plan">Plan</label>
  <select <?= $c->model('plan', false) ?>>
    <option value="free" <?= $c->selected('plan', 'free') ?>>Free</option>
    <option value="pro" <?= $c->selected('plan', 'pro') ?>>Pro</option>
  </select>

  <label class="lc-check"><input type="checkbox" <?= $c->model('terms') ?> <?= $c->checked('terms') ?>> I accept the terms</label>
  <?= $c->error('terms') ?>

  <button type="submit" class="btn btn-primary btn-sm">Sign up</button>
<?php endif; ?>
