<?php
// Universal return contract fixture: echo + explicit string return →
// concat (preserves wire order, "shell first then body").
echo 'A';
return 'B';
