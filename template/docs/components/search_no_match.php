<?php
/**
 * The docs search dropdown when nothing matches. Returned by
 * api/docs/search.php.
 *
 * @var string $query
 */
use function ZealPHP\e;
?>
<div class="api-search-empty">No matches for <code><?= e($query) ?></code>.</div>
