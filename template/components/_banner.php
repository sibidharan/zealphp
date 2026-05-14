<script>
  // Inline so we hide before first paint — no flash.
  (function () {
    try {
      if (localStorage.getItem('zealphp-alpha-banner-dismissed') === '1') {
        document.documentElement.classList.add('alpha-banner-dismissed');
      }
    } catch (e) {}
  })();
</script>
<div class="alpha-banner" role="status" id="alpha-banner">
  <span class="alpha-banner-tag">Alpha</span>
  <span class="alpha-banner-text">ZealPHP is early-stage and under active development. APIs may change between minor versions until v1.0. Feedback and bug reports welcome on <a href="https://github.com/sibidharan/zealphp/issues" target="_blank" rel="noopener">GitHub</a>.</span>
  <button type="button" class="alpha-banner-dismiss" aria-label="Dismiss alpha banner" onclick="(function(b){try{localStorage.setItem('zealphp-alpha-banner-dismissed','1')}catch(e){}document.documentElement.classList.add('alpha-banner-dismissed');})()">×</button>
</div>
