# Security Policy

## Supported Versions

| Version | Supported |
|---------|-----------|
| 0.1.x   | Yes       |

## Reporting a Vulnerability

**Do not open a public issue for security vulnerabilities.**

Please report security vulnerabilities by emailing **sibi.nandhu@gmail.com**. Include as much detail as possible:

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

## Response

- We will **acknowledge** your report within **48 hours**.
- We will provide a **timeline for a fix** within **7 days**.
- We will coordinate disclosure with you and credit reporters unless anonymity is requested.

Thank you for helping keep ZealPHP secure.
