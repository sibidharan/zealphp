# 07 – File-based API routing

## Table of Contents

- [7.1 Basic example](#71-basic-example)
- [7.2 Module folders](#72-module-folders)
- [7.3 Overriding implicit API routes](#73-overriding-implicit-api-routes)

Legacy PHP was beautiful in its simplicity – drop a `foo.php` under `/api` and request `/api/foo`.  ZealPHP preserves that *developer experience* but modernises it with PSR-7 responses and middleware.

---

## 7.1 Basic example

```
api/
└── hello.php
```

```php
<?php
$hello = function () {
    $this->response(
        $this->json(['message' => 'Hello from ZealPHP API']),
        200
    );
};
```

Requesting **GET /api/hello** automatically:

1. Instantiates `ZealPHP\ZealAPI`.
2. Binds the closure above to the API object (so `$this` works).
3. Executes the closure and returns a pretty-printed JSON response.

No manual route registration required.

---

## 7.2 Module folders

Create sub-folders for logical grouping:

```
api/
└── user/
    ├── login.php    →  /api/user/login
    └── logout.php   →  /api/user/logout
```

If a file calls `$this->json()` or `$this->response()` you get a PSR-7 `Response` object back; returning plain arrays or strings is also supported – ZealPHP will serialise or render accordingly.

---

## 7.3 Overriding implicit API routes

When you need more control you can still register an explicit route and it will trump the implicit rule:

```php
$app->nsRoute('api', 'user/login', [ 'methods' => ['POST'] ], function () {
    // completely custom logic for /api/user/login
});
```

---

Next up: [Porting an Apache/FPM application →](08-porting-from-apache.md)

