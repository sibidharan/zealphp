<?php
declare(strict_types=1);

namespace ZealPHP\Middleware;

use OpenSwoole\Core\Psr\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\RequestContext;

/**
 * HTTP Basic Auth Middleware
 *
 * Validates an `Authorization: Basic <base64(user:pass)>` header against
 * either an htpasswd-formatted credentials file or a callback verifier.
 * Sends `401 Unauthorized` with `WWW-Authenticate: Basic realm="..."` when
 * credentials are missing or invalid — browsers respond by prompting.
 *
 * Apache equivalent:
 *   AuthType Basic
 *   AuthName "Restricted"
 *   AuthUserFile /etc/apache2/.htpasswd
 *   Require valid-user
 *
 * nginx equivalent:
 *   auth_basic "Restricted";
 *   auth_basic_user_file /etc/nginx/.htpasswd;
 *
 * Supported htpasswd hash formats:
 *   - bcrypt ($2y$…)            — `htpasswd -B`
 *   - APR1   ($apr1$…)          — `htpasswd -m` (Apache default)
 *   - SHA-1  ({SHA}base64)      — `htpasswd -s` (legacy; insecure, accepted)
 *   - crypt() (anything else)   — `htpasswd -d` (legacy DES)
 *
 * Plain text passwords are NEVER accepted from the file — a missing prefix
 * is treated as an opaque crypt() hash, which means setting `user:hunter2`
 * literally in the file will not authenticate `hunter2`.
 *
 * Usage in app.php:
 *
 *   // File-based
 *   $app->addMiddleware(new \ZealPHP\Middleware\BasicAuthMiddleware(
 *       htpasswdFile: '/etc/zealphp/.htpasswd',
 *       realm:        'Admin Area',
 *   ));
 *
 *   // Callback-based (e.g. validate against your DB)
 *   $app->addMiddleware(new \ZealPHP\Middleware\BasicAuthMiddleware(
 *       verify: fn(string $u, string $p): bool => User::verify($u, $p),
 *       realm:  'API',
 *   ));
 */
class BasicAuthMiddleware implements MiddlewareInterface
{
    /** @var callable|null fn(string $user, string $pass): bool */
    private $verify;

    /** @var array<string, string>|null user => hash, lazily parsed from htpasswdFile */
    private ?array $htpasswdCache = null;
    private ?int $htpasswdMtime  = null;

    /**
     * @param string|null   $htpasswdFile  Path to an htpasswd-formatted file
     * @param callable|null $verify        Alternative: fn(string $user, string $pass): bool
     * @param string        $realm         Realm name shown in browser prompt
     */
    public function __construct(
        private ?string $htpasswdFile = null,
        ?callable       $verify       = null,
        private string  $realm        = 'Restricted',
    ) {
        $this->verify = $verify;

        if ($htpasswdFile === null && $verify === null) {
            throw new \InvalidArgumentException(
                'BasicAuthMiddleware requires either $htpasswdFile or $verify callable'
            );
        }
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $authz = $request->getHeaderLine('Authorization');
        $creds = $this->parseAuthorization($authz);
        if ($creds === null) {
            return $this->challenge();
        }

        [$user, $pass] = $creds;
        if (!$this->verifyCredentials($user, $pass)) {
            return $this->challenge();
        }

        return $handler->handle($request);
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function parseAuthorization(string $header): ?array
    {
        if (!preg_match('/^Basic\s+([A-Za-z0-9+\/=]+)\s*$/i', $header, $m)) {
            return null;
        }
        $decoded = base64_decode($m[1], true);
        if ($decoded === false || !str_contains($decoded, ':')) {
            return null;
        }
        [$user, $pass] = explode(':', $decoded, 2);
        if ($user === '') {
            return null;
        }
        return [$user, $pass];
    }

    private function verifyCredentials(string $user, string $pass): bool
    {
        if ($this->verify !== null) {
            return (bool)($this->verify)($user, $pass);
        }
        return $this->verifyHtpasswd($user, $pass);
    }

    private function verifyHtpasswd(string $user, string $pass): bool
    {
        $map = $this->loadHtpasswd();
        if ($map === null || !isset($map[$user])) {
            return false;
        }
        $hash = $map[$user];

        // bcrypt — modern default
        if (str_starts_with($hash, '$2y$') || str_starts_with($hash, '$2a$') || str_starts_with($hash, '$2b$')) {
            return password_verify($pass, $hash);
        }
        // APR1 (Apache MD5) — `htpasswd -m`
        if (str_starts_with($hash, '$apr1$')) {
            return hash_equals($hash, $this->crypt_apr1_md5($pass, $hash));
        }
        // SHA-1 — `htpasswd -s` (legacy, but htpasswd still emits it)
        if (str_starts_with($hash, '{SHA}')) {
            $expected = '{SHA}' . base64_encode(sha1($pass, true));
            return hash_equals($hash, $expected);
        }
        // Fallback: crypt() with whatever salt the hash encodes (legacy DES,
        // SHA-256 $5$, SHA-512 $6$, etc.). crypt() returns the input on
        // unsupported algorithms, so hash_equals still correctly rejects.
        $computed = crypt($pass, $hash);
        return hash_equals($hash, $computed);
    }

    /**
     * Parse an htpasswd file (user:hash per line, # comments, blank lines).
     * Re-reads on mtime change so live edits work in dev without restarts.
     *
     * @return array<string, string>|null
     */
    private function loadHtpasswd(): ?array
    {
        if ($this->htpasswdFile === null) {
            return null;
        }
        $mtime = @filemtime($this->htpasswdFile);
        if ($mtime === false) {
            return null;
        }
        if ($this->htpasswdCache !== null && $this->htpasswdMtime === $mtime) {
            return $this->htpasswdCache;
        }
        $contents = @file_get_contents($this->htpasswdFile);
        if ($contents === false) {
            return null;
        }
        $map = [];
        foreach (explode("\n", $contents) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!str_contains($line, ':')) {
                continue;
            }
            [$u, $h] = explode(':', $line, 2);
            $u = trim($u);
            $h = trim($h);
            if ($u !== '' && $h !== '') {
                $map[$u] = $h;
            }
        }
        $this->htpasswdCache = $map;
        $this->htpasswdMtime = $mtime;
        return $map;
    }

    /**
     * APR1 (Apache MD5) reimplementation. PHP's crypt() doesn't support $apr1$.
     * Algorithm reference: Apache's apr_md5_encode.c.
     */
    private function crypt_apr1_md5(string $password, string $hashOrSalt): string
    {
        // Extract salt from "$apr1$<salt>$<hash>" or "$apr1$<salt>"
        $parts = explode('$', $hashOrSalt);
        // parts: [0]='', [1]='apr1', [2]=salt, [3]=hash (optional)
        $salt = $parts[2] ?? '';
        $salt = substr($salt, 0, 8);

        $len  = strlen($password);
        $text = $password . '$apr1$' . $salt;
        $bin  = md5($password . $salt . $password, true);
        for ($i = $len; $i > 0; $i -= 16) {
            $text .= substr($bin, 0, min(16, $i));
        }
        for ($i = $len; $i > 0; $i >>= 1) {
            $text .= ($i & 1) ? "\x00" : $password[0];
        }
        $bin = md5($text, true);
        for ($i = 0; $i < 1000; $i++) {
            $new  = ($i & 1) ? $password : $bin;
            if ($i % 3) $new .= $salt;
            if ($i % 7) $new .= $password;
            $new .= ($i & 1) ? $bin : $password;
            $bin  = md5($new, true);
        }

        $tmp = '';
        for ($i = 0; $i < 5; $i++) {
            $k = $i + 6;
            $j = $i + 12;
            if ($j === 16) $j = 5;
            $tmp = $bin[$i] . $bin[$k] . $bin[$j] . $tmp;
        }
        $tmp = "\x00\x00" . $bin[11] . $tmp;
        $encoded = strtr(
            substr(base64_encode($tmp), 2),
            'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/',
            './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz'
        );

        return '$apr1$' . $salt . '$' . $encoded;
    }

    private function challenge(): ResponseInterface
    {
        $g = RequestContext::instance();
        $g->status = 401;
        $resp = $g->zealphp_response;
        $realmHeader = 'Basic realm="' . str_replace('"', '\\"', $this->realm) . '"';
        if ($resp !== null) {
            $resp->header('WWW-Authenticate', $realmHeader);
        }
        return new Response('Unauthorized', 401, '', [
            'Content-Type'     => 'text/plain',
            'WWW-Authenticate' => $realmHeader,
        ]);
    }
}
