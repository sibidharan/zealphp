# 06 – Implicit routing (API & public folder)

## Table of Contents

- [1. API Implicit Routes](#1-api-implicit-routes)
  - [1.1 URL patterns](#11-url-patterns)
  - [1.2 Execution flow](#12-execution-flow)
  - [1.3 Error handling](#13-error-handling)
  - [1.4 Overriding an implicit API route](#14-overriding-an-implicit-api-route)
- [2. Public Folder Routes](#2-public-folder-routes)
  - [2.1 Extension hiding & security](#21-extension-hiding--security)
  - [2.2 Directory indexes](#22-directory-indexes)
  - [2.3 Executing PHP safely](#23-executing-php-safely)
  - [2.4 Coroutines & implicit includes](#24-coroutines--implicit-includes)
- [3. Order of evaluation](#3-order-of-evaluation)
- [4. Configuration summary](#4-configuration-summary)

*Implicit* routes are automatically provided by ZealPHP – you do **not** have to register them manually.  They are designed to mimic a traditional Apache / Nginx + PHP-FPM setup so that older code-bases run without modification.

There are two major groups:

1. **API implicit routes** – map URLs to files inside the `api/` directory.
2. **Public folder routes** – serve files (PHP *and* static assets) from the `public/` directory.

Because the framework creates these routes *after* loading your `route/*.php` files, any **explicit** route you declare will override the implicit one.

### Quick reference

| Group | Purpose | URL Prefix | Maps to | Supported HTTP verbs |
|-------|---------|-----------|---------|----------------------|
| *API implicit* | File-based API endpoints that return JSON or PSR-7 responses | `/api` | `api/**/*.php` | ALL (GET, POST, PUT, DELETE, PATCH, OPTIONS, HEAD) |
| *Public implicit* | Classic public document root – serves HTML/PHP pages and static assets | `/` (root) | `public/` | GET, HEAD (other verbs fall back to 405) |
| *Fallback 404* | Built-in 404 response | — | — | ALL |

The rest of this chapter dives into the details of each group.

---

## 1. API Implicit Routes

### 1.1 URL patterns

| URL Pattern | Maps to file |
|-------------|--------------|
| `/api/{script}` | `api/{script}.php` |
| `/api/{module}/{script}` | `api/{module}/{script}.php` |

Both patterns accept all common HTTP verbs (**GET**, **POST**, **PUT**, **DELETE**, **PATCH**, **OPTIONS**).

### 1.2 Execution flow

1. When a request matches the pattern, ZealPHP instantiates `ZealPHP\ZealAPI`.
2. The PHP file is included **once**.  It must define a *variable* whose name matches the script file.  Example for `hello.php`:

   ```php
   <?php
   $hello = function () {
       $this->response(
           $this->json(['msg' => 'Hi']),
           200
       );
   };
   ```

3. The closure is bound to the API object so `$this` refers to `ZealAPI` – granting access to helper methods such as `json()`, `response()` and `paramsExists()`.
4. Whatever the closure returns becomes the PSR-7 `Response`:
   * `ResponseInterface` – returned as-is.
   * `array` / `object` – serialised into JSON.
   * `string` – echoed to the client.

### 1.3 Error handling

If the file or method does not exist ZealPHP automatically returns **404** with a JSON body:

```json
{ "error": "method_not_found" }
```

You can catch exceptions inside your closure and rethrow – `ZealAPI` will generate a trace for debugging when `App::$display_errors` is enabled.

### 1.4 Overriding an implicit API route

Need special behaviour for `/api/user/login`?  Just add an explicit route **before** running the server:

```php
$app->nsRoute('api', 'user/login', ['methods' => ['POST']], function () {
    // custom logic …
});
```

Because explicit routes are registered *first* they take precedence.

---

## 2. Public Folder Routes

ZealPHP treats the `public/` directory as your **document root**, just like Apache does.  The implicit routes cover three common scenarios:

1. **Homepage** – `/` maps to `public/index.php`.
2. **Pretty URLs for top-level pages** – `/about` → `public/about.php`.
3. **Nested folders** – `/blog/2024/hello-world` → `public/blog/2024/hello-world.php` *or* `public/blog/2024/hello-world/index.php`.

Static assets (CSS, JS, images…) are served by OpenSwoole’s built-in static handler **before** the PHP routing kicks in, so they never hit userland code and remain blazing fast.

### 2.1 Extension hiding & security

By default `App::$ignore_php_ext` is **true** which means direct access to `*.php` files is blocked with **403 Forbidden**.  Users must request the clean URL version instead (`/contact` instead of `/contact.php`).

If your legacy app relies on visible `.php` extensions you can switch the behaviour off *before* calling `run()`:

```php
ZealPHP\App::$ignore_php_ext = false;
```

### 2.2 Directory indexes

When the requested path is a **directory** and no explicit route exists, ZealPHP looks for an `index.php` file inside that directory – exactly how Apache’s *DirectoryIndex* works.

### 2.3 Executing PHP safely

Every PHP file included via implicit routes first passes through `includeCheck()` which you can override to implement custom allow/deny logic (for example to ban execution in `public/uploads`).

### 2.4 Coroutines & implicit includes

If `App::$coproc_implicit_request_handler` is **true** (default) ZealPHP runs the included script inside a **child OpenSwoole process**.  This avoids mixing user code with the framework’s stack and guarantees a clean environment per request.  You can disable the behaviour if you require direct inclusion.

---

## 3. Order of evaluation

1. **Explicit** routes registered in `app.php` and `/route/*.php`.
2. **Implicit API** routes.
3. **Implicit public** routes.
4. 404 handler (simple built-in page).

---

## 4. Configuration summary

| Feature | Property | Default |
|---------|----------|---------|
| Hide `.php` extension & block direct access | `App::$ignore_php_ext` | `true` |
| Reconstruct PHP superglobals | `App::$superglobals` | `true` |
| Run implicit includes in a child process | `App::$coproc_implicit_request_handler` | `true` |

---

Next up: [File-based API routing →](07-api-routing.md)
