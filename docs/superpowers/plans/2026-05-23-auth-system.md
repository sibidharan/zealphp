# Auth System (P1.3) — Implementation Plan

**Issue:** [#84](https://github.com/sibidharan/zealphp/issues/84) Framework-level OAuth2/OIDC provider system
**Roadmap:** P1.3 in `docs/architecture/2026-05-23-v0.3.0-roadmap.md`
**Status:** design + Phase 1 implementation

---

## Mapping issue #84 → ZealPHP architecture

The labs-dashboard codebase has ~150 lines of inline GitLab OAuth + PKCE + token-exchange + userinfo + MongoDB user-sync in `home.php`. Adding a second provider would duplicate the whole thing. The framework should give every ZealPHP app:

1. **A provider interface** so adding a new OAuth source is one class
2. **Normalized result objects** (`UserInfo` + `TokenResponse`) so handlers don't switch on provider field names
3. **PKCE + state handling internally** — apps never touch the code_verifier
4. **Auto-registered routes** per provider (`/auth/{provider}/login`, `/callback`, `/refresh`, `/logout`)
5. **An `onAuthenticated` hook** for app-specific user sync (the GitLab `users()->me()` + MongoDB sync stays in the app)
6. **Multi-domain credential keying** (one provider, different client_id per `HTTP_HOST`)

This sits cleanly alongside the existing `ZealPHP\Learn\Auth` (username/password auth used by Lessons 17+18). The two are unrelated — `Learn\Auth` is the lesson's local-account demo; the new `ZealPHP\Auth` is the framework's OAuth/OIDC provider system.

## Cross-issue dependencies

- **Token refresh** is naturally a scheduled job — could be a cron entry once #85 (P1.5) ships. Not a Phase-1 dependency.
- **Long-running providers** (slow userinfo fetch, slow MongoDB sync in `onAuthenticated`) can dispatch via Pool workers once #86 (P2.1 full) ships. Not a Phase-1 dependency.
- **No #85/#86 blocker** for auth Phase 1.

---

## File structure

| File | Role |
|---|---|
| `src/Auth/OAuthProvider.php` | Interface every provider implements |
| `src/Auth/UserInfo.php` | Normalized user-info value object |
| `src/Auth/TokenResponse.php` | Normalized token-exchange response |
| `src/Auth/Pkce.php` | PKCE code_verifier + S256 challenge helper |
| `src/Auth/StateBag.php` | Session-backed state + code_verifier storage |
| `src/Auth/GenericOIDCProvider.php` | OIDC discovery-based provider (covers most OIDC IdPs) |
| `src/Auth/GitLabProvider.php` | Extends Generic + GitLab-specific scopes/quirks |
| `src/Auth/GoogleProvider.php` | Extends Generic + Google-specific knobs |
| `src/Auth/GitHubProvider.php` | OAuth2-only (no OIDC discovery); hardcoded endpoints + `/user`+`/user/emails` userinfo |
| `src/Auth/AuthFacade.php` | The `$app->auth()` registry — `addProvider`, `onAuthenticated`, route wiring |
| `src/App.php` | Add `App::auth()` accessor that returns the facade |
| `tests/Unit/Auth/*` | Provider construction + URL building + PKCE round-trip + StateBag tests |

---

## Phase 1 — minimum viable Auth (this round)

Deliverable: any OIDC-compliant provider works out of the box. GitLab and Google fall under "Generic OIDC + scopes config"; GitHub stays explicit (no OIDC).

### P1.A — Core interfaces + value objects

```php
interface OAuthProvider {
    public function name(): string;
    public function authorizationUrl(string $state, string $codeChallenge, array $extra = []): string;
    public function exchangeCode(string $code, string $codeVerifier): TokenResponse;
    public function userInfo(string $accessToken): UserInfo;
    public function refresh(string $refreshToken): TokenResponse;
    public function endSessionUrl(?string $idToken, string $postLogoutRedirect): ?string;
}

final class UserInfo {
    public function __construct(
        public readonly string  $sub,
        public readonly string  $name,
        public readonly ?string $email,
        public readonly ?string $username,
        public readonly ?string $avatarUrl,
        public readonly array   $raw,        // full provider response
    ) {}
}

final class TokenResponse {
    public function __construct(
        public readonly string  $accessToken,
        public readonly ?string $refreshToken,
        public readonly ?string $idToken,
        public readonly int     $expiresIn,
        public readonly string  $tokenType,
        public readonly array   $scopes,
        public readonly array   $raw,
    ) {}
}
```

### P1.B — PKCE + StateBag

PKCE: `bin2hex(random_bytes(32))` for the verifier; SHA-256 → base64url for the challenge.
StateBag: session-backed `{state → ['code_verifier' => …, 'provider' => …, 'created_at' => …]}`. Single-use; deletes on consume; expires after 10 minutes.

### P1.C — GenericOIDCProvider

Constructor takes:
- `issuer` (e.g. `https://accounts.google.com`)
- `clientId`, `clientSecret`, `redirectUri`
- `scopes` (defaults to `['openid', 'profile', 'email']`)

On first use, fetches `{issuer}/.well-known/openid-configuration` via `HTTP::get` (the rename you just shipped pays off — typed response, transport-error handling). Caches the metadata in `Cache` (TTL 24h).

Provider methods build URLs/POSTs against the discovered endpoints. UserInfo normalisation maps standard OIDC claims (`sub`, `name`, `email`, `preferred_username`, `picture`) to `UserInfo`; passes everything else through in `raw`.

### P1.D — `App::auth()` facade

```php
$app->auth()->addProvider('gitlab', new GenericOIDCProvider([
    'issuer' => 'https://git.selfmade.ninja',
    'client_id' => '...',
    'client_secret' => '...',
    'redirect_uri' => 'https://labs.selfmade.ninja/auth/gitlab/callback',
    'scopes' => ['openid', 'profile', 'email', 'read_api'],
]));

$app->auth()->onAuthenticated(function (UserInfo $user, TokenResponse $tokens, string $provider) {
    // App-specific — labs would do MongoDB sync here
    $_SESSION['user'] = ['email' => $user->email, 'name' => $user->name];
    $_SESSION['tokens'] = $tokens->raw;
});
```

### P1.E — Auto-registered routes

When a provider is added:
- `GET /auth/{name}/login` — generate state + code_verifier, store in StateBag, redirect to provider's authorization URL
- `GET /auth/{name}/callback?code=...&state=...` — verify state, exchange code, fetch userinfo, invoke `onAuthenticated`, redirect to `?return=` or `/`
- `POST /auth/{name}/refresh` — refresh access token using stored refresh_token from session
- `GET /auth/logout` — clear session; if `id_token` present, redirect to provider's `end_session_endpoint` for OIDC logout

### P1.F — Multi-domain credential keying

```php
$app->auth()->addProvider('gitlab', new GenericOIDCProvider([
    'issuer' => 'https://git.selfmade.ninja',
    'credentials' => [
        'labs.selfmade.ninja'    => ['client_id' => 'A', 'client_secret' => 'B', 'redirect_uri' => '…'],
        'labsdev.selfmade.ninja' => ['client_id' => 'C', 'client_secret' => 'D', 'redirect_uri' => '…'],
    ],
]));
```

Resolution: at request time, look up `$_SERVER['HTTP_HOST']` against the credentials map. Single-domain shorthand still works (one `client_id` + `client_secret`).

## Phase 2 — provider-specific niceties (next round)

- `GitHubProvider` — no OIDC; hardcoded endpoints, `/user` + `/user/emails` userinfo
- ID token signature validation (JWKS fetch + verify)
- Token refresh scheduled job (once P1.5 ships)
- Provider-specific scopes registry (`GoogleProvider::SCOPES_DRIVE`, etc.)

## Phase 3 — admin + observability

- `/auth/_status` endpoint — registered providers + their config (no secrets)
- `Auth::stats()` — login counts, token-refresh counts, callback failures
- Per-provider rate limiting on login endpoints

---

## Lesson chain integration (out of scope for first round)

A `learn/auth-oauth` lesson lands AFTER the framework code is in. Walks through GitLab OAuth setup → `addProvider` → `onAuthenticated` user-sync hook → handler use of `$_SESSION['user']`. ~250 LOC of lesson template.

## Migration path for labs-dashboard

The issue documents 4 steps:
1. Extract current `home.php` OAuth logic into `GitLabProvider` (Phase 2)
2. Move PKCE/state handling into framework (Phase 1.B)
3. Keep `onAuthenticated` hook for the GitLab `users()->me()` + MongoDB sync (lives in app)
4. `home.php` reduces to: check session → if not logged in, redirect to `/auth/gitlab/login`

For the **first PR** that lands the auth system, labs migration is not required — the framework's Phase 1 stands alone and is testable against any OIDC provider.

## Out of scope (explicit)

| | Why |
|---|---|
| Full ACL / RBAC | App-level — labs has its own privilege system |
| API token auth (separate JWT validation for bearer-protected APIs) | Different concern; not the same as user-facing OAuth |
| Social login UI buttons | Apps draw their own login page |
| Username/password local auth | That's `ZealPHP\Learn\Auth` (lessons); not the new framework auth |

## Order of work (this branch)

1. Save this plan + commit
2. Implement Phase 1.A (interface + value objects)
3. Implement Phase 1.B (Pkce + StateBag)
4. Implement Phase 1.C (GenericOIDCProvider)
5. Implement Phase 1.D + 1.E (AuthFacade + auto routes)
6. Implement Phase 1.F (multi-domain creds)
7. Unit tests at each step
8. Integration smoke test against a real OIDC provider (Google's OIDC discovery is free + public; we can point to `https://accounts.google.com` and at least verify metadata fetch + URL building)
9. Commit per phase
