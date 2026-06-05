# Security Policy

## Supported Versions

| Version | Supported |
|---------|-----------|
| 0.4.x   | Yes       |
| < 0.4   | No        |

ZealPHP is pre-1.0 (alpha): only the latest minor (currently **0.4.x**) receives
security fixes. Upgrade to the newest `0.4.z` patch for the current fixes.

## Reporting a Vulnerability

**Do not open a public issue for security vulnerabilities.**

Please report security vulnerabilities by emailing **sibi@selfmade.ninja**. Include as much detail as possible:

- Description of the vulnerability
- Steps to reproduce
- Potential impact
- Suggested fix (if any)

## Scope

The following areas are in scope for security reports:

- Framework core (`src/`)
- Session handling and session storage
- uopz function overrides
- Coroutine isolation and per-request state
- Middleware (CORS, ETag, compression)
- WebSocket connection handling

### Out of Scope

- Demo website content (`public/`, `template/pages/`)
- Example files (`examples/`)
- Benchmark scripts (`scripts/`)

## Researching coroutine isolation

The per-coroutine request-state isolation runtime — the [ext-zealphp](https://github.com/sibidharan/ext-zealphp)
extension, exercised through `App::mode('coroutine-legacy')` — is the
highest-value security-research surface: a break is a **cross-tenant data leak**
(one request reading another's session/globals) or a **use-after-free** (worker
crash / DoS). It is researched **through ZealPHP**, not the extension alone.

If you're auditing it, start with
**[docs/coroutine-isolation-security-research.md](docs/coroutine-isolation-security-research.md)**
— the vulnerability-class taxonomy (UAF / cross-tenant leak / unbounded leak), the
high-risk code surface, the known-open frontier, and the ASAN + Valgrind
reproduction methodology.

## Response

- We will **acknowledge** your report within **48 hours**.
- We will provide a **timeline for a fix** within **7 days**.
- We will coordinate disclosure with you and credit reporters unless anonymity is requested.

Thank you for helping keep ZealPHP secure.
