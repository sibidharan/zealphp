# ZealPHP App Compatibility Lab

A reproducible Docker setup for testing real-world PHP applications on ZealPHP and comparing performance against Apache+mod_php (the FPM/CGI baseline).

## What this lab does

1. **Spins up** a shared MySQL container with per-app databases pre-created and a universal `testuser/testpass` credential pair.
2. **Installs** apps from GitHub releases / Composer packages.
3. **Configures** each app's config file to point at the shared MySQL using a deterministic credential set.
4. **Runs install wizards** non-interactively via `curl` (or `wp-cli`, `drush`, etc., where the app provides one).
5. **Tests core flows** through each app: auth, CRUD, search, settings — captured as `tests/Integration/AppCompatibility*Test.php`.
6. **Benchmarks** each app against an Apache+mod_php reference container running the SAME code base, capturing RPS, p99 latency, memory footprint.

## Why these specific apps

The 32-app sweep matrix tests framework correctness — does ZealPHP serve the app without crashing under each of the 5 execution modes? That's a different question from "does the app actually work end-to-end." This lab answers the second.

The matrix already showed:
- Mode 1 Pool serves 13 apps cleanly (Adminer, phpMyAdmin, Joomla, Kanboard, Roundcube, Matomo, Cacti, phpLiteAdmin, PrivateBin, Vanilla, traditional, TinyFileManager, Nextcloud).
- 5 more apps redirect to install wizards (WordPress, DokuWiki, FreshRSS, MyBB, Piwigo).
- The rest fail at boot due to per-app config (composer install, DB, system extensions).

This lab takes the working set + the install-wizard set and runs them through real user flows.

## Running

```bash
# One-time setup — provisions MySQL, ZealPHP, Apache reference containers
./setup.sh

# Wire each app's config to point at the shared MySQL
./configure-apps.sh

# Run install wizards non-interactively
./install-apps.sh

# Exercise each app's core flow + capture pass/fail
./test-apps.sh

# Benchmark vs Apache reference
./bench-apps.sh
```

## Per-app harnesses

Each app lives in its own subdirectory with:
- `install.sh` — POST to the install wizard with our test credentials
- `test.sh` — Login + perform 3-5 core actions + verify
- `bench.sh` — Pick a hot path (e.g., post-list, login form, homepage) + `ab -n 1000 -c 50`

Apps with end-to-end harnesses:

| App | Install | Test flow | Bench |
|---|---|---|---|
| WordPress | wp-cli + install.php POST | Login → create post → view post | Homepage + post list |
| Joomla | install/index.php POST | Login → create article | Frontend article |
| Drupal | drush si | Login → create node | Anonymous homepage |
| MediaWiki | mw-install.php POST | Login → create page | Article view |
| Adminer | N/A (no install) | Login to MySQL → run query | Login form |
| phpMyAdmin | N/A | Login → SELECT | Login form |
| PrivateBin | N/A | Create paste → fetch | Paste creation |
| FreshRSS | install wizard | Login → add feed | Feed list |
| Roundcube | install/index.php POST | IMAP login → list inbox | Login form |

## Comparison metrics

For each app's hot path we capture:

| Metric | ZealPHP M1 Pool | ZealPHP M5 Coro | Apache+mod_php |
|---|---|---|---|
| Requests/sec | (target ≥ Apache) | (target > Apache) | baseline |
| p50 latency | ms | ms | ms |
| p99 latency | ms | ms | ms |
| Memory per worker (RSS) | MB | MB | MB |
| Total memory at 50 concurrent | MB | MB | MB |

## Adding a new app

1. Drop the app source into `apps/<name>/`.
2. Add `apps/<name>/install.sh`, `test.sh`, `bench.sh`.
3. Append to the per-app table above.
4. Re-run `./test-apps.sh` to verify it joins the green column.
