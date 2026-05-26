/*
 * ext-zealphp — per-request function overrides for long-running PHP servers.
 *
 * Replaces ~15 PHP built-in functions (header, session_start, exec, etc.)
 * with user-supplied callbacks so each coroutine/request gets its own
 * response/session state. Drop-in replacement for uopz_set_return() with
 * a much smaller attack surface — allowlist-only, no class manipulation.
 *
 * API:
 *   zealphp_override(string $name, callable $cb): bool
 *   zealphp_restore(string $name): bool
 *   zealphp_restore_all(): void
 */

#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php.h"
#include "php_ini.h"
#include "ext/standard/info.h"
#include "zend_exceptions.h"
#include "php_zealphp.h"

/* ── Storage ─────────────────────────────────────────────────────────── */

/* original handler pointers, keyed by lowercase function name */
static HashTable zealphp_orig_handlers;

/* PHP callable callbacks, keyed by lowercase function name */
static HashTable zealphp_callbacks;

/* ── Allowlist ───────────────────────────────────────────────────────── */

static const char *zealphp_allowed[] = {
    /* response */
    "header",
    "header_remove",
    "headers_list",
    "headers_sent",
    "setcookie",
    "setrawcookie",
    "http_response_code",
    "header_register_callback",
    /* output control */
    "flush",
    "ob_flush",
    "ob_end_flush",
    "ob_implicit_flush",
    "output_add_rewrite_var",
    "output_reset_rewrite_vars",
    /* process/connection */
    "set_time_limit",
    "ignore_user_abort",
    "connection_status",
    "connection_aborted",
    "register_shutdown_function",
    /* error handling */
    "error_log",
    "error_reporting",
    "set_error_handler",
    "restore_error_handler",
    "set_exception_handler",
    "restore_exception_handler",
    /* file upload */
    "is_uploaded_file",
    "move_uploaded_file",
    /* info */
    "phpinfo",
    "php_sapi_name",
    /* input filtering */
    "filter_input",
    "filter_input_array",
    /* session */
    "session_start",
    "session_id",
    "session_status",
    "session_name",
    "session_write_close",
    "session_destroy",
    "session_unset",
    "session_regenerate_id",
    "session_get_cookie_params",
    "session_set_cookie_params",
    "session_cache_limiter",
    "session_cache_expire",
    "session_commit",
    "session_abort",
    "session_encode",
    "session_decode",
    "session_save_path",
    "session_module_name",
    /* exec family */
    "shell_exec",
    "exec",
    "system",
    "passthru",
    NULL
};

static bool zealphp_is_allowed(const char *name, size_t len)
{
    for (const char **p = zealphp_allowed; *p; p++) {
        if (strlen(*p) == len && memcmp(*p, name, len) == 0) {
            return true;
        }
    }
    return false;
}

/* ── Generic handler ─────────────────────────────────────────────────── */

static ZEND_NAMED_FUNCTION(zealphp_dispatch)
{
    zend_string *fname = execute_data->func->common.function_name;
    zend_string *lc = zend_string_tolower(fname);

    zval *cb = zend_hash_find(&zealphp_callbacks, lc);
    zend_string_release(lc);

    if (!cb || Z_TYPE_P(cb) == IS_UNDEF) {
        RETURN_NULL();
    }

    uint32_t argc = ZEND_CALL_NUM_ARGS(execute_data);
    zval *args = NULL;
    if (argc > 0) {
        args = ZEND_CALL_ARG(execute_data, 1);
    }

    zval retval;
    ZVAL_UNDEF(&retval);

    if (call_user_function(NULL, NULL, cb, &retval, argc, args) == SUCCESS) {
        if (Z_TYPE(retval) != IS_UNDEF) {
            ZVAL_COPY_VALUE(return_value, &retval);
        } else {
            zval_ptr_dtor(&retval);
        }
    } else {
        zval_ptr_dtor(&retval);
        if (EG(exception)) {
            return;
        }
        php_error_docref(NULL, E_WARNING,
            "ext-zealphp: callback for %s failed", ZSTR_VAL(fname));
    }
}

/* ── zealphp_override(string $name, callable $cb): bool ──────────── */

PHP_FUNCTION(zealphp_override)
{
    zend_string *func_name;
    zend_fcall_info fci;
    zend_fcall_info_cache fcc;

    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_STR(func_name)
        Z_PARAM_FUNC(fci, fcc)
    ZEND_PARSE_PARAMETERS_END();

    zend_string *lc = zend_string_tolower(func_name);

    /* allowlist check */
    if (!zealphp_is_allowed(ZSTR_VAL(lc), ZSTR_LEN(lc))) {
        php_error_docref(NULL, E_WARNING,
            "ext-zealphp: overriding '%s' is not allowed", ZSTR_VAL(func_name));
        zend_string_release(lc);
        RETURN_FALSE;
    }

    /* already overridden? */
    if (zend_hash_exists(&zealphp_orig_handlers, lc)) {
        php_error_docref(NULL, E_WARNING,
            "ext-zealphp: '%s' is already overridden — restore first",
            ZSTR_VAL(func_name));
        zend_string_release(lc);
        RETURN_FALSE;
    }

    /* find original */
    zend_function *func = zend_hash_find_ptr(CG(function_table), lc);
    if (!func) {
        php_error_docref(NULL, E_WARNING,
            "ext-zealphp: function '%s' not found", ZSTR_VAL(func_name));
        zend_string_release(lc);
        RETURN_FALSE;
    }

    if (func->type != ZEND_INTERNAL_FUNCTION) {
        php_error_docref(NULL, E_WARNING,
            "ext-zealphp: can only override internal (C) functions, not user functions");
        zend_string_release(lc);
        RETURN_FALSE;
    }

    /* save original handler */
    zend_hash_add_new_ptr(&zealphp_orig_handlers, lc,
                          (void *)func->internal_function.handler);

    /* save callback */
    zval cb_copy;
    ZVAL_COPY(&cb_copy, &fci.function_name);
    zend_hash_update(&zealphp_callbacks, lc, &cb_copy);

    /* swap handler */
    func->internal_function.handler = zealphp_dispatch;

    zend_string_release(lc);
    RETURN_TRUE;
}

/* ── zealphp_restore(string $name): bool ─────────────────────────── */

PHP_FUNCTION(zealphp_restore)
{
    zend_string *func_name;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STR(func_name)
    ZEND_PARSE_PARAMETERS_END();

    zend_string *lc = zend_string_tolower(func_name);

    void *orig = zend_hash_find_ptr(&zealphp_orig_handlers, lc);
    if (!orig) {
        zend_string_release(lc);
        RETURN_FALSE;
    }

    zend_function *func = zend_hash_find_ptr(CG(function_table), lc);
    if (func) {
        func->internal_function.handler = (zif_handler)orig;
    }

    zend_hash_del(&zealphp_orig_handlers, lc);
    zend_hash_del(&zealphp_callbacks, lc);

    zend_string_release(lc);
    RETURN_TRUE;
}

/* ── zealphp_restore_all(): void ─────────────────────────────────── */

PHP_FUNCTION(zealphp_restore_all)
{
    zend_string *key;
    void *orig;

    ZEND_PARSE_PARAMETERS_NONE();

    ZEND_HASH_FOREACH_STR_KEY_PTR(&zealphp_orig_handlers, key, orig) {
        zend_function *func = zend_hash_find_ptr(CG(function_table), key);
        if (func) {
            func->internal_function.handler = (zif_handler)orig;
        }
    } ZEND_HASH_FOREACH_END();

    zend_hash_clean(&zealphp_orig_handlers);
    zend_hash_clean(&zealphp_callbacks);
}

/* ── Superglobals management ─────────────────────────────────────── */

/* Helper: overwrite a superglobal in the executor symbol table. */
static void zealphp_set_superglobal(const char *name, size_t name_len, zval *value)
{
    zval *existing = zend_hash_str_find(&EG(symbol_table), name, name_len);
    if (existing) {
        zval_ptr_dtor(existing);
        ZVAL_COPY(existing, value);
    } else {
        zval copy;
        ZVAL_COPY(&copy, value);
        zend_hash_str_add_new(&EG(symbol_table), name, name_len, &copy);
    }
}

/* zealphp_superglobals_set(array $g, $p, $c, $s, $f, $r): void
 * Overwrites $_GET, $_POST, $_COOKIE, $_SERVER, $_FILES, $_REQUEST
 * with the supplied arrays. Called by ZealPHP at request start. */
PHP_FUNCTION(zealphp_superglobals_set)
{
    zval *get, *post, *cookie, *server, *files, *request;

    ZEND_PARSE_PARAMETERS_START(6, 6)
        Z_PARAM_ARRAY(get)
        Z_PARAM_ARRAY(post)
        Z_PARAM_ARRAY(cookie)
        Z_PARAM_ARRAY(server)
        Z_PARAM_ARRAY(files)
        Z_PARAM_ARRAY(request)
    ZEND_PARSE_PARAMETERS_END();

    zealphp_set_superglobal("_GET",     sizeof("_GET")-1,     get);
    zealphp_set_superglobal("_POST",    sizeof("_POST")-1,    post);
    zealphp_set_superglobal("_COOKIE",  sizeof("_COOKIE")-1,  cookie);
    zealphp_set_superglobal("_SERVER",  sizeof("_SERVER")-1,  server);
    zealphp_set_superglobal("_FILES",   sizeof("_FILES")-1,   files);
    zealphp_set_superglobal("_REQUEST", sizeof("_REQUEST")-1, request);
}

/* zealphp_superglobals_clear(): void
 * Resets all superglobals to empty arrays. Called at request end
 * to prevent cross-request leakage in coroutine mode. */
PHP_FUNCTION(zealphp_superglobals_clear)
{
    ZEND_PARSE_PARAMETERS_NONE();

    const char *names[] = {"_GET","_POST","_COOKIE","_SERVER","_FILES","_REQUEST",NULL};
    for (const char **n = names; *n; n++) {
        zval empty;
        array_init(&empty);
        zealphp_set_superglobal(*n, strlen(*n), &empty);
        zval_ptr_dtor(&empty);
    }
}

/* zealphp_superglobals_save(): array
 * Snapshots all 6 superglobals into one array for coroutine storage. */
PHP_FUNCTION(zealphp_superglobals_save)
{
    ZEND_PARSE_PARAMETERS_NONE();

    array_init(return_value);
    const char *names[] = {"_GET","_POST","_COOKIE","_SERVER","_FILES","_REQUEST",NULL};
    for (const char **n = names; *n; n++) {
        zval *sg = zend_hash_str_find(&EG(symbol_table), *n, strlen(*n));
        if (sg) {
            zval copy;
            ZVAL_COPY(&copy, sg);
            add_assoc_zval(return_value, *n, &copy);
        }
    }
}

/* zealphp_superglobals_restore(array $snapshot): void
 * Restores superglobals from a snapshot saved by zealphp_superglobals_save(). */
PHP_FUNCTION(zealphp_superglobals_restore)
{
    zval *snapshot;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_ARRAY(snapshot)
    ZEND_PARSE_PARAMETERS_END();

    const char *names[] = {"_GET","_POST","_COOKIE","_SERVER","_FILES","_REQUEST",NULL};
    for (const char **n = names; *n; n++) {
        zval *val = zend_hash_str_find(Z_ARRVAL_P(snapshot), *n, strlen(*n));
        if (val) {
            zealphp_set_superglobal(*n, strlen(*n), val);
        }
    }
}

/* ── Module lifecycle ────────────────────────────────────────────── */

PHP_MINIT_FUNCTION(zealphp)
{
    zend_hash_init(&zealphp_orig_handlers, 32, NULL, NULL, 1);
    zend_hash_init(&zealphp_callbacks, 32, NULL, ZVAL_PTR_DTOR, 1);
    return SUCCESS;
}

PHP_MSHUTDOWN_FUNCTION(zealphp)
{
    /* restore any still-overridden functions */
    zend_string *key;
    void *orig;

    ZEND_HASH_FOREACH_STR_KEY_PTR(&zealphp_orig_handlers, key, orig) {
        zend_function *func = zend_hash_find_ptr(CG(function_table), key);
        if (func) {
            func->internal_function.handler = (zif_handler)orig;
        }
    } ZEND_HASH_FOREACH_END();

    zend_hash_destroy(&zealphp_orig_handlers);
    zend_hash_destroy(&zealphp_callbacks);
    return SUCCESS;
}

PHP_MINFO_FUNCTION(zealphp)
{
    php_info_print_table_start();
    php_info_print_table_row(2, "ext-zealphp", "enabled");
    php_info_print_table_row(2, "Version", PHP_ZEALPHP_VERSION);
    php_info_print_table_row(2, "Purpose",
        "Per-request function overrides for long-running PHP servers");
    php_info_print_table_end();
}

/* ── Function entries ────────────────────────────────────────────── */

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_zealphp_override, 0, 2, _IS_BOOL, 0)
    ZEND_ARG_TYPE_INFO(0, function_name, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, callback, IS_CALLABLE, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_zealphp_restore, 0, 1, _IS_BOOL, 0)
    ZEND_ARG_TYPE_INFO(0, function_name, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_zealphp_restore_all, 0, 0, IS_VOID, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_zealphp_superglobals_set, 0, 6, IS_VOID, 0)
    ZEND_ARG_TYPE_INFO(0, get, IS_ARRAY, 0)
    ZEND_ARG_TYPE_INFO(0, post, IS_ARRAY, 0)
    ZEND_ARG_TYPE_INFO(0, cookie, IS_ARRAY, 0)
    ZEND_ARG_TYPE_INFO(0, server, IS_ARRAY, 0)
    ZEND_ARG_TYPE_INFO(0, files, IS_ARRAY, 0)
    ZEND_ARG_TYPE_INFO(0, request, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_zealphp_superglobals_clear, 0, 0, IS_VOID, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_zealphp_superglobals_save, 0, 0, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_zealphp_superglobals_restore, 0, 1, IS_VOID, 0)
    ZEND_ARG_TYPE_INFO(0, snapshot, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

static const zend_function_entry zealphp_functions[] = {
    PHP_FE(zealphp_override,               arginfo_zealphp_override)
    PHP_FE(zealphp_restore,                arginfo_zealphp_restore)
    PHP_FE(zealphp_restore_all,            arginfo_zealphp_restore_all)
    PHP_FE(zealphp_superglobals_set,       arginfo_zealphp_superglobals_set)
    PHP_FE(zealphp_superglobals_clear,     arginfo_zealphp_superglobals_clear)
    PHP_FE(zealphp_superglobals_save,      arginfo_zealphp_superglobals_save)
    PHP_FE(zealphp_superglobals_restore,   arginfo_zealphp_superglobals_restore)
    PHP_FE_END
};

/* ── Module entry ────────────────────────────────────────────────── */

zend_module_entry zealphp_module_entry = {
    STANDARD_MODULE_HEADER,
    "zealphp",
    zealphp_functions,
    PHP_MINIT(zealphp),
    PHP_MSHUTDOWN(zealphp),
    NULL, /* RINIT */
    NULL, /* RSHUTDOWN */
    PHP_MINFO(zealphp),
    PHP_ZEALPHP_VERSION,
    STANDARD_MODULE_PROPERTIES
};

#ifdef COMPILE_DL_ZEALPHP
ZEND_GET_MODULE(zealphp)
#endif
