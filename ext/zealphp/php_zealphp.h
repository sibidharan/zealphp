#ifndef PHP_ZEALPHP_H
#define PHP_ZEALPHP_H

extern zend_module_entry zealphp_module_entry;
#define phpext_zealphp_ptr &zealphp_module_entry

#define PHP_ZEALPHP_VERSION "0.1.0"

PHP_MINIT_FUNCTION(zealphp);
PHP_MSHUTDOWN_FUNCTION(zealphp);
PHP_MINFO_FUNCTION(zealphp);

PHP_FUNCTION(zealphp_override);
PHP_FUNCTION(zealphp_restore);
PHP_FUNCTION(zealphp_restore_all);
PHP_FUNCTION(zealphp_superglobals_set);
PHP_FUNCTION(zealphp_superglobals_clear);
PHP_FUNCTION(zealphp_superglobals_save);
PHP_FUNCTION(zealphp_superglobals_restore);

#endif
