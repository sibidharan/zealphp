# 02 – Routing Fundamentals

## Table of Contents

- [2.1 Named parameters](#21-named-parameters)
- [2.2 Namespace routes (`nsRoute`)](#22-namespace-routes-nsroute)
- [2.3 Pattern routes (`patternRoute`)](#23-pattern-routes-patternroute)
- [2.4 Default & Implicit routes](#24-default--implicit-routes)
- [2.5 Route middleware](#25-route-middleware)

Routing in ZealPHP is deliberately familiar if you come from frameworks such as Laravel, Slim, or even ExpressJS.  A **route** is simply a **PHP callable** associated with a path-pattern and an optional list of HTTP methods.

```php
$app->route('/hello/{name}', function (string $name) {
    echo "<h1>Hello $name</h1>";
});

// Accept only POST/PUT
$app->route('/article/{id}', [ 'methods' => ['POST', 'PUT'] ], function ($id) {
    // …
});
```

## 2.1 Named parameters

Segments wrapped in curly braces become *named* parameters.  The value is injected into your handler by position **in declaration order** – just like modern PHP attribute routing.

```php
$app->route('/user/{id}/post/{postId}', function ($id, $postId) {
    // $id     – first placeholder
    // $postId – second placeholder
});
```

## 2.2 Namespace routes (`nsRoute`)

Large code-bases quickly end up with dozens of routes.  To keep things tidy you can attach multiple handlers under a **namespace**:

```php
$app->nsRoute('api', '/todo/{id}', function ($id) {
    echo json_encode(['todo' => $id]);
});

# → matches   GET /api/todo/42
```

`nsRoute()` accepts the same `$options` array and handler signature as `route()`.

## 2.3 Pattern routes (`patternRoute`)

When the path cannot be expressed with simple placeholders, reach for `patternRoute()` and give it a regular expression:

```php
$app->patternRoute('/raw/(?P<rest>.*)', ['methods' => ['GET']], function ($rest) {
    echo "You requested: $rest";
});
```

The example above behaves similarly to Apache’s famous `RewriteRule ^raw/(.*)$` but is written in pure PHP and resolved at *O(1)* thanks to ZealPHP’s route tree.

## 2.4 Default & Implicit routes

Out of the box, ZealPHP exposes **implicit routes** that mimic the standard Apache/FPM setup so inheriting legacy applications is painless.  These are covered in detail in chapter 06 but the short version is:

* `/public/**` – static assets and PHP files served directly.
* `/api/**` – file-based API endpoints.

If you later create an *explicit* route that overlaps an implicit one, your explicit handler wins – giving you a natural migration path.

## 2.5 Route middleware

Middleware is covered in detail in chapter 04 but it is worth mentioning that all routes flow through PSR-15 middleware stack first.  This makes authentication, CSRF, CORS and logging straightforward.

---

Next up: [Superglobals & the `G` helper →](03-superglobals.md)

