<h1>Hello Zeal PHP - HTML</h1>

<?
use function ZealPHP\zlog;
zlog("index processed");
echo(file_get_contents("php://input"));
?>