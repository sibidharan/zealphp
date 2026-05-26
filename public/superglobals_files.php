<?php
if ($_FILES) echo 'FILE:' . $_FILES['test']['name'];
else echo 'NO FILE';
