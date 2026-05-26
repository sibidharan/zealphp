dnl config.m4 for ext-zealphp

PHP_ARG_ENABLE([zealphp],
  [whether to enable zealphp support],
  [AS_HELP_STRING([--enable-zealphp],
    [Enable zealphp — per-request function overrides for long-running PHP servers])])

if test "$PHP_ZEALPHP" != "no"; then
  PHP_NEW_EXTENSION(zealphp, zealphp.c, $ext_shared)
fi
