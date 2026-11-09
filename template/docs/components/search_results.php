<?php
/**
 * The docs search dropdown (template/docs/components/search.php swaps it into
 * #api-search-results). Returned by api/docs/search.php.
 *
 * @var array<string, list<array{url: string, name: string, parent: string, summary: string}>> $groups
 *      hits by kind (ZealPHP\Docs\Search::find()); empty kinds are skipped
 */
use function ZealPHP\e;
?>
<ul class="api-search-list">
<?php foreach ($groups as $kind => $entries): ?>
    <?php if (!$entries) continue; ?>
    <li class="api-search-group">
        <h6 class="api-search-kind"><?= e($kind) ?></h6>
        <?php foreach ($entries as $entry): ?>
            <a href="<?= e($entry['url']) ?>"
               class="api-search-item"
               hx-boost="false">
                <span class="api-search-name"><?= e($entry['name']) ?></span>
                <?php if ($entry['parent'] !== ''): ?>
                    <span class="api-search-parent"><?= e($entry['parent']) ?></span>
                <?php endif; ?>
                <?php if ($entry['summary'] !== ''): ?>
                    <span class="api-search-summary"><?= e($entry['summary']) ?></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </li>
<?php endforeach; ?>
</ul>
