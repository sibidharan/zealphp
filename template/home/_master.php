<?
use ZealPHP\App;
?>

<!DOCTYPE html>
<html lang="en">
<? App::render('/home/_head'); ?>
<body>

    <!-- Header Section -->
    <header>
        <h1>My Website</h1>
        <p>Welcome to my awesome website!</p>
    </header>

    <?App::render('/home/content');?>

<?App::render('/home/_footer');?>

</body>
</html>
