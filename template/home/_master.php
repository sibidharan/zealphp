<?
use ZealPHP\App;
?>

<!DOCTYPE html>
<html lang="en">
<? App::render('_head', ['title' => $title]);
?>
<body>

    <!-- Header Section -->
    <header>
        <h1><?=$title?> 123</h1>
        <p><?=$description?></p>
    </header>

    <?App::render('content');?>

<?App::render('_footer');?>

</body>
</html>
