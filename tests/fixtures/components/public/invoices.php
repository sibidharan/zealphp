<?php
// App::include() sets SCRIPT_NAME, so 'total' resolves from template/invoices/.
return \ZealPHP\App::component('total', ['invoices' => [['amount' => 2], ['amount' => 3]]]);
