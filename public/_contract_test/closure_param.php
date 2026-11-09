<?php
// Integration-test fixture: not served unless ZEALPHP_TEST_ROUTES is set.
if (!\ZealPHP\env_flag('ZEALPHP_TEST_ROUTES', false)) {
    return 404;
}
// Universal return contract fixture: return a Closure with param injection.
// In-process mode: framework reflects $name from the calling $args.
return function (string $name = 'noname') {
    yield "GREET:";
    yield $name;
};
