<?php echo implode(",", [$name, $c instanceof \ZealPHP\Component\Component ? "c" : "-", is_array($errors) ? "errors" : "-", $slot === null ? "noslot" : (string) $slot]);
