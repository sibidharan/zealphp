<?php
// Integration-test fixture: not served unless ZEALPHP_TEST_ROUTES is set.
if (!\ZealPHP\env_flag('ZEALPHP_TEST_ROUTES', false)) {
    return 404;
}
// Universal return contract fixture: return a Generator → SSR stream.
return (function () {
    yield 'CHUNK1|';
    yield 'CHUNK2|';
    yield 'CHUNK3';
})();
