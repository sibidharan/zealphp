<?php
ob_start();
echo 'Buffered text';
$content = ob_get_clean();
echo 'Cleaned: ' . $content;
flush();
echo ' Flushed text';
