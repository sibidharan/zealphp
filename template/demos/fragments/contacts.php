<?php
/**
 * Body of /demo/fragments/contacts (full-page render inside the demo shell).
 *
 * @var array<int, array{id:int, name:string, role:string, email:string}> $contacts
 */
use ZealPHP\App;
?>
<p class="demo-shell-desc">One template file, two responses. Click <strong>Show details</strong> on any row — htmx requests <code class="demo-inline">?fragment=contact-{id}</code> and the server returns only that row's markup, not the whole page. Open DevTools → Network → XHR to confirm each swap is a single 200 response with just one <code class="demo-inline">&lt;li&gt;</code> in the body. Try <a href="/demo/fragments/contacts?fragment=does-not-exist" target="_blank" rel="noopener">/demo/fragments/contacts?fragment=does-not-exist</a> for the HTTP 404 case — fragment selector with no matching region, no fallback to the full page.</p>
<h2 class="demo-shell-h">Live contacts</h2>
<div class="inject-case">
  <div class="inject-case-body inject-case-body--solo">
    <div class="demo-output">
      <?= App::component('/demos/contacts-list', ['contacts' => $contacts]) ?>
    </div>
  </div>
</div>
