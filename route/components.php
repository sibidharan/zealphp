<?php
/**
 * Live Components demo (#509) for the OSS website: /live-components.
 *
 * Components sign their state with ZEALPHP_APP_KEY. The demo registers only
 * when a key is configured or dev mode is on, so a deployment without a key
 * still boots — it just shows the page without the live demos.
 */

use ZealPHP\App;
use ZealPHP\AppKey;
use ZealPHP\Site\Components\LikeCounter;
use ZealPHP\Site\Components\SignupForm;

use function ZealPHP\elog;

if (AppKey::current() !== null || App::devReload()) {
    App::components([LikeCounter::class, SignupForm::class]);
} elseif (!App::$reloading) {
    elog('[components] demo disabled: set ZEALPHP_APP_KEY (php app.php key:generate) to enable /live-components', 'info');
}
