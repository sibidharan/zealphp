All random ideas that could be features can be just appended here

1. Colorful logs
2.

# ZealPHP core
3. Allow overriding of implicit folder conventions via config helper (while current route-override works, having an explicit `App::implicitRoutes(false)` or path map would improve DX).
4. Replace hard-coded `/etc/environment` loader with `vlucas/phpdotenv` (or fallback) – keep Debian fast-path as optimisation.
5. Investigate lightweight DI container integration (**optional**).  Goal: constructor injection for services without forcing Laravel-style service providers.
6. Expose helper to opt-in to full coroutine mode without remembering the `App::superglobals(false)` incantation (e.g. `php app.php --coroutines`).
7. Evaluate removal of `uopz` dependency long-term (provide polyfill or compile-time switch).
