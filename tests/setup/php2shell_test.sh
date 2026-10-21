#!/bin/bash
# Decision tests for setup.sh's PHP2Shell gate (ensure_php_security_fix).
#
# Hermetic: php, dpkg, dpkg-query and apt-get are PATH shims driven by FAKE_*
# variables, so nothing is installed or upgraded and no network is used. The
# real-package runs (Ubuntu backport, ondrej PPA, php:8.5 image) are recorded
# on PR #500.
#
#   bash tests/setup/php2shell_test.sh
set -u
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

# Load only the PHP2Shell block, not setup.sh's top-level install flow. Colours
# and $SUDO stay unset (empty) in the child shells the cases run in.
# shellcheck disable=SC1090
source <(sed -n '/^PHP2SHELL_FIXED=/,/^docker_setup() {/p' "$ROOT/setup.sh" | sed '$d')

mkdir -p "$WORK/bin" "$WORK/doc"
cat > "$WORK/bin/php" <<'SH'
#!/bin/bash
case "$2" in
  *PHP_MAJOR_VERSION*) v="$FAKE_PHP_VERSION"; echo "${v%.*}" ;;
  *) echo "$FAKE_PHP_VERSION" ;;
esac
SH
cat > "$WORK/bin/dpkg" <<'SH'
#!/bin/bash
[ -n "${FAKE_DPKG_OWNER:-}" ] || exit 1
echo "${FAKE_DPKG_OWNER}: $2"
SH
cat > "$WORK/bin/dpkg-query" <<'SH'
#!/bin/bash
case "$2" in
  *Status*) echo "${FAKE_DPKG_OWNER} installed" ;;
  *) [ "$3" = "${FAKE_REF:-$FAKE_DPKG_OWNER}" ] || exit 1; echo -n "$FAKE_PKG_VERSION" ;;
esac
SH
cat > "$WORK/bin/apt-get" <<'SH'
#!/bin/bash
echo "apt-get $*" >> "$FAKE_APT_LOG"
case "$1" in
  update) [ -n "${FAKE_APT_UPDATE_OUT:-}" ] && echo "$FAKE_APT_UPDATE_OUT"; exit "${FAKE_APT_UPDATE_RC:-0}" ;;
  install) exit "${FAKE_APT_INSTALL_RC:-0}" ;;
  changelog) [ -n "${FAKE_APT_CHANGELOG:-}" ] && printf '%s\n' "$FAKE_APT_CHANGELOG"; exit 0 ;;
esac
SH
chmod +x "$WORK/bin/"*
export PATH="$WORK/bin:$PATH" PHP2SHELL_DOC_DIR="$WORK/doc" FAKE_APT_LOG="$WORK/apt.log"

pass=0 fail=0
# check <name> <expected rc> [VAR=value ...]
check() {
    local name="$1" want="$2" got out
    shift 2
    rm -rf "${WORK:?}/doc/"* && : > "$FAKE_APT_LOG"
    # shellcheck disable=SC2016 # the inner script expands in the child shell
    out="$(env FAKE_DPKG_OWNER= FAKE_REF= FAKE_PKG_VERSION= FAKE_APT_CHANGELOG= FAKE_DOC_CHANGELOG= "$@" bash -c '
        if [ -n "$FAKE_DOC_CHANGELOG" ]; then
            mkdir -p "$PHP2SHELL_DOC_DIR/${FAKE_REF}"
            printf "%s\n" "$FAKE_DOC_CHANGELOG" | gzip > "$PHP2SHELL_DOC_DIR/${FAKE_REF}/changelog.Debian.gz"
        fi
        '"$(declare -f php2shell_floor php2shell_version_ok php2shell_changelog_status php2shell_refuse ensure_php_security_fix); PHP2SHELL_FIXED='$PHP2SHELL_FIXED'; PHP2SHELL_CVES='$PHP2SHELL_CVES'"'
        ensure_php_security_fix' 2>&1)"
    got=$?
    if [ "$got" = "$want" ]; then
        pass=$((pass + 1)); echo "ok   $name"
    else
        fail=$((fail + 1)); echo "FAIL $name (rc $got, want $want)"; printf '     %s\n' "${out//$'\n'/$'\n'     }"
    fi
    LAST_OUT="$out"
}
FIXED_LOG="php8.3 (8.3.6-0ubuntu0.24.04.13) noble-security; urgency=medium
  * SECURITY UPDATE: PHP2Shell
    - CVE-2026-109618
    - CVE-2026-109619"
OLD_LOG="php8.3 (8.3.6-0ubuntu0.24.04.12) noble-security; urgency=medium
  * SECURITY UPDATE: something older
    - CVE-2025-1861"
U=(FAKE_PHP_VERSION=8.3.6 FAKE_DPKG_OWNER=php8.3-cli FAKE_REF=php8.3-common)
S=(FAKE_PHP_VERSION=8.4.27 FAKE_DPKG_OWNER=php8.4-cli FAKE_REF=php8.4-common FAKE_PKG_VERSION=8.4.27-1+ubuntu24.04.1+deb.sury.org+1)

check "ubuntu backport, changelog lists both CVEs"      0 "${U[@]}" FAKE_PKG_VERSION=8.3.6-0ubuntu0.24.04.13 "FAKE_DOC_CHANGELOG=$FIXED_LOG"
check "ubuntu old build, changelog lacks the CVEs"      1 "${U[@]}" FAKE_PKG_VERSION=8.3.6-0ubuntu0.24.04.12 "FAKE_DOC_CHANGELOG=$OLD_LOG"
check "ubuntu, only one CVE in changelog"               1 "${U[@]}" FAKE_PKG_VERSION=8.3.6-0ubuntu0.24.04.13 "FAKE_DOC_CHANGELOG=CVE-2026-109618"
check "ubuntu, /usr/share/doc absent -> apt changelog"  0 "${U[@]}" FAKE_PKG_VERSION=8.3.6-0ubuntu0.24.04.13 "FAKE_APT_CHANGELOG=$FIXED_LOG"
grep -q 'changelog .*php8.3-common=8.3.6-0ubuntu0.24.04.13' "$FAKE_APT_LOG" || { fail=$((fail + 1)); echo "FAIL apt-get changelog not asked for the installed version"; }
check "ubuntu, no changelog anywhere -> refuse"         1 "${U[@]}" FAKE_PKG_VERSION=8.3.6-0ubuntu0.24.04.13
grep -q 'cannot be verified' <<<"$LAST_OUT" || { fail=$((fail + 1)); echo "FAIL no-evidence case did not say so"; }
check "ubuntu, no evidence + ALLOW=1"                   0 "${U[@]}" FAKE_PKG_VERSION=8.3.6-0ubuntu0.24.04.13 ZEALPHP_ALLOW_VULNERABLE_PHP=1
check "ondrej 8.4.27"                                   0 "${S[@]}"
check "ondrej 8.4.26 (release-day PPA)"                 1 FAKE_PHP_VERSION=8.4.26 FAKE_DPKG_OWNER=php8.4-cli FAKE_REF=php8.4-common FAKE_PKG_VERSION=8.4.26-1+ubuntu24.04.1+deb.sury.org+1
grep -q '8.4.26-1+ubuntu24.04.1+deb.sury.org+1' <<<"$LAST_OUT" || { fail=$((fail + 1)); echo "FAIL refusal does not print the package version"; }
check "ondrej 8.4.26, changelog cannot rescue it"       1 FAKE_PHP_VERSION=8.4.26 FAKE_DPKG_OWNER=php8.4-cli FAKE_REF=php8.4-common FAKE_PKG_VERSION=8.4.26-1+ubuntu24.04.1+deb.sury.org+1 "FAKE_DOC_CHANGELOG=$FIXED_LOG"
check "packages.sury.org 8.4.26, changelog cannot rescue it" 1 FAKE_PHP_VERSION=8.4.26 FAKE_DPKG_OWNER=php8.4-cli FAKE_REF=php8.4-common FAKE_PKG_VERSION=8.4.26-1+0~20261001.50+debian12~1.gbp1a2b3c "FAKE_DOC_CHANGELOG=$FIXED_LOG"
check "packages.sury.org 8.4.27"                        0 FAKE_PHP_VERSION=8.4.27 FAKE_DPKG_OWNER=php8.4-cli FAKE_REF=php8.4-common FAKE_PKG_VERSION=8.4.27-1+0~20261021.57+debian12~1.gbp7636b9
check "apt-get update runs before the upgrade"          0 "${S[@]}"
if [ "$(head -n1 "$FAKE_APT_LOG")" != "apt-get update" ] || ! grep -q -- '--only-upgrade' "$FAKE_APT_LOG"; then
    fail=$((fail + 1)); echo "FAIL apt log: $(tr '\n' ';' < "$FAKE_APT_LOG")"
fi
check "apt-get update fails -> refuse"                  1 "${S[@]}" FAKE_APT_UPDATE_RC=100
grep -q -- '--only-upgrade' "$FAKE_APT_LOG" && { fail=$((fail + 1)); echo "FAIL upgraded after a failed refresh"; }
check "apt-get update exits 0 but a fetch failed"       1 "${S[@]}" "FAKE_APT_UPDATE_OUT=W: Failed to fetch http://archive.ubuntu.com/ubuntu/dists/noble/InRelease  Temporary failure resolving 'archive.ubuntu.com'"
grep -q -- '--only-upgrade' "$FAKE_APT_LOG" && { fail=$((fail + 1)); echo "FAIL upgraded after a partial refresh"; }
check "apt-get install fails -> refuse"                 1 "${S[@]}" FAKE_APT_INSTALL_RC=100
check "debian package at upstream release"              0 FAKE_PHP_VERSION=8.4.27 FAKE_DPKG_OWNER=php8.4-cli FAKE_REF=php8.4-common FAKE_PKG_VERSION=8.4.27-1
check "-common absent, falls back to -cli"              0 FAKE_PHP_VERSION=8.3.6 FAKE_DPKG_OWNER=php8.3-cli FAKE_REF=php8.3-cli FAKE_PKG_VERSION=8.3.6-0ubuntu0.24.04.13 "FAKE_DOC_CHANGELOG=$FIXED_LOG"
check "non-apt 8.5.12"                                  0 FAKE_PHP_VERSION=8.5.12
check "non-apt 8.5.11"                                  1 FAKE_PHP_VERSION=8.5.11
check "non-apt 8.5.11 + ALLOW=1"                        0 FAKE_PHP_VERSION=8.5.11 ZEALPHP_ALLOW_VULNERABLE_PHP=1
check "non-apt 8.3.36"                                  0 FAKE_PHP_VERSION=8.3.36
check "non-apt 8.6.0 (newer minor, not covered)"        0 FAKE_PHP_VERSION=8.6.0
[ -s "$FAKE_APT_LOG" ] && { fail=$((fail + 1)); echo "FAIL non-apt path ran apt-get"; }

echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ]
