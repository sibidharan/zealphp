<?php
// Universal return contract fixture: echo first then return Generator →
// wire order is the echoed prefix THEN the generator chunks.
echo 'SHELL|';
return (function () {
    yield 'BODY1|';
    yield 'BODY2';
})();
