<?php
// Universal return contract fixture: return a Generator → SSR stream.
return (function () {
    yield 'CHUNK1|';
    yield 'CHUNK2|';
    yield 'CHUNK3';
})();
