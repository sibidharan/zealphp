<?php return (function () {
    yield "A";
    yield from \ZealPHP\App::renderStream('sem_child', [], 'tests/fixtures/render');
    yield "B";
})();
