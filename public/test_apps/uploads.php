<?php
if ($_FILES) {
    echo 'FILES: '; print_r($_FILES);
} else {
    echo '<form method="POST" enctype="multipart/form-data"><input type="file" name="test"><input type="submit"></form>';
}
