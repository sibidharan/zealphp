<?php
/**
 * The error line under a learn login/register form (auth, notes, tictactoe
 * lessons). Returned by api/learn/login.php and api/learn/register.php as the
 * htmx fragment for a failed attempt.
 *
 * @var string $message
 */
use function ZealPHP\e;
?>
<p class="auth-error"><?= e($message) ?></p>
