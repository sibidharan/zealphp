<?php
/**
 * The home page chat's reply once a visitor has used up the hourly quota.
 * route/chat.php streams it as one SSE token.
 *
 * @var int|float $minutes until the quota resets
 */
use function ZealPHP\e;
?>
<p>Rate limit reached (60 questions/hour). Try again in ~<?= e($minutes) ?> minutes.</p>
