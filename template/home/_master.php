<?
use ZealPHP\App;
?>

<!DOCTYPE html>
<html lang="en">
<? App::render('/home/_head'); ?>
<body>

    <!-- Header Section -->
    <header>
        <h1><?=$title?></h1>
        <p><?=$description?></p>
    </header>

    <?App::render('/home/content');?>

<?App::render('/home/_footer');?>

</body>
</html>
