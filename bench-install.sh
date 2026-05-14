#!/usr/bin/env bash
# ZealPHP bench-install — get a fresh Ubuntu/Debian machine ready to run
# scripts/bench.sh in one command.
#
# Usage (as the user you want to own the clone):
#   curl -fsSL https://php.zeal.ninja/bench-install.sh | sudo bash
#
# What it does:
#   1. Runs setup.sh   (PHP 8.3, OpenSwoole, uopz, composer)
#   2. apt installs    (wrk, apache2-utils for ab, git)
#   3. Clones          (https://github.com/sibidharan/zealphp → $HOME/zealphp)
#   4. composer install
#   5. Prints the bench command and exits
#
# Env vars:
#   BENCH_CLONE_DIR     where to clone (default: $HOME/zealphp under invoking user)
#   ZEALPHP_NO_PROMPT   set to 1 to skip the welcome prompt (auto for non-TTY)

set -euo pipefail

RED="\e[1;31m"
GREEN="\e[1;32m"
YELLOW="\e[1;33m"
MAGENTA="\e[1;35m"
WHITE="\e[1;37m"
RESET="\e[0m"

export DEBIAN_FRONTEND=noninteractive
export TZ="${TZ:-Etc/UTC}"

if [ "$EUID" -ne 0 ]; then
    echo -e "${RED}bench-install.sh needs root to install packages.${RESET}"
    echo -e "${YELLOW}Re-run with: curl -fsSL https://php.zeal.ninja/bench-install.sh | sudo bash${RESET}"
    exit 1
fi

ORIG_USER="${SUDO_USER:-root}"
if [ "$ORIG_USER" = "root" ]; then
    ORIG_HOME="${HOME:-/root}"
else
    ORIG_HOME="$(getent passwd "$ORIG_USER" | cut -d: -f6)"
fi
BENCH_CLONE_DIR="${BENCH_CLONE_DIR:-$ORIG_HOME/zealphp}"

run_as_user() {
    if [ "$ORIG_USER" = "root" ]; then
        "$@"
    else
        sudo -u "$ORIG_USER" "$@"
    fi
}

echo -e "${MAGENTA}=== ZealPHP bench install ===${RESET}"
echo -e "Clone target:      ${WHITE}$BENCH_CLONE_DIR${RESET}"
echo -e "Owner:             ${WHITE}$ORIG_USER${RESET}"
echo

# ---------------------------------------------------------------------------
# 1. PHP 8.3 + OpenSwoole + uopz + composer (via setup.sh)
# ---------------------------------------------------------------------------
echo -e "${GREEN}[1/4] Running PHP/OpenSwoole/uopz/composer setup...${RESET}"
SETUP_URL="${SETUP_URL:-https://php.zeal.ninja/install.sh}"
curl -fsSL "$SETUP_URL" | ZEALPHP_NO_PROMPT=1 bash
echo

# ---------------------------------------------------------------------------
# 2. Bench tools
# ---------------------------------------------------------------------------
echo -e "${GREEN}[2/4] Installing wrk + ab + git...${RESET}"
apt-get install -y --no-install-recommends wrk apache2-utils git ca-certificates >/dev/null
echo -e "  wrk:        $(wrk --version 2>&1 | head -1 || echo 'installed')"
echo -e "  ab:         $(ab -V 2>&1 | head -1)"
echo -e "  git:        $(git --version)"
echo

# ---------------------------------------------------------------------------
# 3. Clone the repo (as the invoking user, so file ownership is sane)
# ---------------------------------------------------------------------------
echo -e "${GREEN}[3/4] Cloning ZealPHP into $BENCH_CLONE_DIR...${RESET}"
if [ -d "$BENCH_CLONE_DIR/.git" ]; then
    echo -e "${YELLOW}  $BENCH_CLONE_DIR already a git checkout — pulling latest instead.${RESET}"
    run_as_user git -C "$BENCH_CLONE_DIR" pull --ff-only
else
    run_as_user git clone --depth 1 https://github.com/sibidharan/zealphp.git "$BENCH_CLONE_DIR"
fi
echo

# ---------------------------------------------------------------------------
# 4. composer install
# ---------------------------------------------------------------------------
echo -e "${GREEN}[4/4] composer install...${RESET}"
run_as_user bash -c "cd '$BENCH_CLONE_DIR' && composer install --no-interaction --prefer-dist"
echo

# ---------------------------------------------------------------------------
# Done — tell the user what to run next
# ---------------------------------------------------------------------------
echo -e "${GREEN}========================================${RESET}"
echo -e "${GREEN}Bench environment ready.${RESET}"
echo -e "${GREEN}========================================${RESET}"
echo
echo -e "Run the single-stack sweep (matches PERF.md):"
echo -e "  ${WHITE}cd $BENCH_CLONE_DIR${RESET}"
echo -e "  ${WHITE}scripts/bench.sh --tool ab --requests 50000 --workers 4 --threads 4 \\${RESET}"
echo -e "  ${WHITE}                 --task-workers 0 --paths /raw/bench,/json --p1000${RESET}"
echo
echo -e "Or the 3-way comparison (ZealPHP vs raw Node vs Express):"
echo -e "  ${WHITE}cd /tmp && npm install autocannon express${RESET}"
echo -e "  ${WHITE}cd $BENCH_CLONE_DIR && ./bench/compare-3way/run.sh${RESET}"
echo
echo -e "Methodology + tables: ${MAGENTA}https://php.zeal.ninja/performance${RESET}"
