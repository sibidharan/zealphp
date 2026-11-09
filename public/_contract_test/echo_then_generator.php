<?php
// Integration-test fixture: not served unless ZEALPHP_TEST_ROUTES is set.
if (!\ZealPHP\env_flag('ZEALPHP_TEST_ROUTES', false)) {
    return 404;
}
// Universal return contract fixture: echo first then return Generator →
// wire order is the echoed prefix THEN the generator chunks.
echo 'SHELL|';
return (function () {
    yield 'BODY1|';
    yield 'BODY2';
})();
