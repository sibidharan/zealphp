#!/bin/bash

# Color codes for output messages
RED="\e[1;31m"
GREEN="\e[1;32m"
YELLOW="\e[1;33m"
MAGENTA="\e[1;35m"
WHITE="\e[1;37m"
RESET="\e[0m"

# Parallelism for every source build here (OpenSwoole / ext-zealphp / uopz).
# Defaults to 4 rather than $(nproc): nproc over-reports inside CPU/memory-limited
# containers, so an all-cores build OOMs. Also exported as MAKEFLAGS so nested
# makes inherit the cap. Override with ZEALPHP_BUILD_JOBS (e.g. =8, or =$(nproc)).
ZEALPHP_BUILD_JOBS="${ZEALPHP_BUILD_JOBS:-4}"
export MAKEFLAGS="-j${ZEALPHP_BUILD_JOBS}"

# ─────────────────────────────────────────────────────────────────────────────
# OpenSwoole installation — build from a TAGGED source release.
#
# `pecl install openswoole` no longer works (OpenSwoole's release pipeline moved
# off the PECL channel), and PIE/Packagist publishes only the unpinned
# `dev-master` branch — neither yields a pinned, reproducible build. Downloading
# the tagged source and running phpize/configure/make is the one method that works
# regardless of any PECL/PIE/Packagist supply-chain state, so it's the only path
# we use. Defined before docker_setup() so the Docker path can use it too; works
# as root (Docker, $SUDO empty) and unprivileged (apt/macOS).
# ─────────────────────────────────────────────────────────────────────────────

# Build + install openswoole.so from a tagged source release. $1 = git ref
# override; default is the latest stable release (OPENSWOOLE_VERSION /
# OPENSWOOLE_SOURCE_REF override). Fetches the tagged source two ways for
# resilience — git clone, then the GitHub release tarball — trying the ref both
# with and without a leading "v". Bypasses PECL/PIE/Packagist entirely, so it works
# irrespective of their state. Does NOT enable the extension — the caller does that
# (openswoole needs ext-sockets loaded first; see the zz-*.ini note at each site).
install_openswoole_source() {
    local ref="${1:-}"
    [ -z "$ref" ] && ref="${OPENSWOOLE_SOURCE_REF:-v26.2.0}"
    local alt
    case "$ref" in v*) alt="${ref#v}" ;; *) alt="v${ref}" ;; esac

    local tmpdir src
    tmpdir="$(mktemp -d)"
    src="$tmpdir/src"
    echo -e "${YELLOW}Building OpenSwoole from source (openswoole/ext-openswoole @ ${ref}).${RESET}"

    # macOS/Homebrew: keg-only libs (openssl, c-ares, ...) aren't on the default
    # search path, so point pkg-config at the brew kegs the build needs. Best-effort
    # (not exercised in CI); guarded on Darwin so it's a no-op on Linux.
    if [ "$(uname -s)" = "Darwin" ] && command -v brew >/dev/null 2>&1; then
        local kl kp
        for kl in openssl@3 c-ares nghttp2 brotli curl; do
            kp="$(brew --prefix "$kl" 2>/dev/null)"
            [ -n "$kp" ] && [ -d "$kp/lib/pkgconfig" ] && PKG_CONFIG_PATH="$kp/lib/pkgconfig:${PKG_CONFIG_PATH:-}"
        done
        export PKG_CONFIG_PATH
    fi

    # Fetch the tagged source straight from GitHub — git clone first, then the
    # release tarball as a second transport. Both bypass PECL/PIE/Packagist.
    if ! git clone --depth 1 --branch "$ref" https://github.com/openswoole/ext-openswoole.git "$src" 2>/dev/null \
       && ! git clone --depth 1 --branch "$alt" https://github.com/openswoole/ext-openswoole.git "$src" 2>/dev/null; then
        echo -e "${YELLOW}git clone failed — trying the release tarball.${RESET}"
        mkdir -p "$src"
        local fetched=0 t
        for t in "$ref" "$alt"; do
            if curl -fsSL "https://github.com/openswoole/ext-openswoole/archive/refs/tags/${t}.tar.gz" -o "$tmpdir/src.tgz" 2>/dev/null \
               && tar xzf "$tmpdir/src.tgz" -C "$src" --strip-components=1 2>/dev/null; then
                fetched=1
                break
            fi
        done
        if [ "$fetched" -ne 1 ]; then
            echo -e "${RED}Failed to fetch OpenSwoole source (${ref} / ${alt}) via git or tarball.${RESET}"
            rm -rf "$tmpdir"
            return 1
        fi
    fi

    # OpenSwoole hard-#errors ("Enable c-ares support, require c-ares library") if
    # --enable-cares is set but libcares isn't linkable, and --with-postgres needs
    # libpq. Enable each only when it actually links, so a missing optional dev
    # library degrades that one feature instead of failing the whole build. (The
    # apt/brew deps install both; this stays graceful if one is somehow absent.)
    local flags="--enable-sockets --enable-openssl --enable-http2 --enable-mysqlnd --enable-hook-curl"
    if echo 'int main(void){return 0;}' | cc -xc - -lcares -o "$tmpdir/conftest" 2>/dev/null; then
        flags="$flags --enable-cares"
    else
        echo -e "${YELLOW}libcares not found — building without --enable-cares (install libc-ares-dev for it).${RESET}"
    fi
    if command -v pg_config >/dev/null 2>&1 || echo 'int main(void){return 0;}' | cc -xc - -lpq -o "$tmpdir/conftest" 2>/dev/null; then
        flags="$flags --with-postgres"
    else
        echo -e "${YELLOW}libpq not found — building without PostgreSQL support (install libpq-dev for it).${RESET}"
    fi
    rm -f "$tmpdir/conftest"

    # shellcheck disable=SC2086 # $flags is a deliberately word-split flag list
    if (cd "$src" \
            && phpize \
            && ./configure $flags \
            && make -j"${ZEALPHP_BUILD_JOBS}" \
            && ${SUDO:-} make install); then
        rm -rf "$tmpdir"
        echo -e "${GREEN}OpenSwoole built and installed from source.${RESET}"
        return 0
    fi
    rm -rf "$tmpdir"
    echo -e "${RED}OpenSwoole source build failed.${RESET}"
    return 1
}

# Build + install openswoole.so. Always builds from a tagged source release (see
# install_openswoole_source) — the pinned, reproducible, supply-chain-proof path.
# Does NOT enable the extension; the caller enables it with the correct,
# late-sorting ini name (openswoole needs ext-sockets loaded first). Honors
# OPENSWOOLE_VERSION as the git ref to build (default: latest stable tag).
build_install_openswoole() {
    install_openswoole_source "${OPENSWOOLE_VERSION:-}"
}

docker_setup() {
    set -e
    export DEBIAN_FRONTEND=noninteractive

    echo -e "${YELLOW}Installing Docker image dependencies for ZealPHP.${RESET}"
    apt-get update
    apt-get install -y --no-install-recommends \
        apache2-utils \
        autoconf \
        automake \
        libtool \
        ca-certificates \
        curl \
        g++ \
        git \
        iproute2 \
        libbrotli-dev \
        libc-ares-dev \
        libcurl4-openssl-dev \
        libnghttp2-dev \
        libpcre2-dev \
        libpq-dev \
        libssl-dev \
        make \
        nodejs \
        pkg-config \
        procps \
        unzip \
        wrk \
        zlib1g-dev
    rm -rf /var/lib/apt/lists/*

    echo -e "${YELLOW}Installing bundled PHP extensions needed by OpenSwoole.${RESET}"
    docker-php-ext-install sockets pcntl mysqli pdo_mysql

    echo -e "${YELLOW}Installing OpenSwoole for the Docker image (tagged source build).${RESET}"
    build_install_openswoole || { echo -e "${RED}OpenSwoole source build failed.${RESET}"; exit 1; }
    # Enable with a late-sorting ini name so openswoole loads AFTER ext-sockets
    # (docker-php-ext-sockets.ini), satisfying its socket_ce symbol dependency.
    docker-php-ext-enable --ini-name zz-openswoole.ini openswoole
    php -r 'exit(extension_loaded("openswoole") ? 0 : 1);' || { echo -e "${RED}OpenSwoole built but failed to load.${RESET}"; exit 1; }

    echo -e "${YELLOW}Installing ext-zealphp ${ZEALPHP_EXT_VERSION:-v0.3.52} for Docker image.${RESET}"
    # Pinned for reproducibility + to dodge the pre-0.3.16 compile break on modern
    # toolchains (GCC 14 hardens -Wincompatible-pointer-types to an error; the old
    # zend_alter_ini_entry_ex call passed the entry struct instead of the name).
    # NOTE: the ext has its OWN 0.3.x version line — do NOT confuse it with the
    # framework's 0.3.x. Override with ZEALPHP_EXT_VERSION.
    git clone --depth 1 --branch "${ZEALPHP_EXT_VERSION:-v0.3.52}" https://github.com/sibidharan/ext-zealphp.git /tmp/ext-zealphp
    if (cd /tmp/ext-zealphp && phpize && ./configure --enable-zealphp && make -j"${ZEALPHP_BUILD_JOBS}" && make install); then
        docker-php-ext-enable --ini-name zz-zealphp.ini zealphp
        rm -rf /tmp/ext-zealphp
    else
        echo -e "${YELLOW}ext-zealphp failed. Falling back to uopz.${RESET}"
        rm -rf /tmp/ext-zealphp
        if [ -n "${UOPZ_VERSION:-}" ]; then
            pecl install "uopz-${UOPZ_VERSION}"
        elif ! pecl install uopz 2>/dev/null; then
            git clone --depth 1 https://github.com/krakjoe/uopz.git /tmp/uopz-src
            (cd /tmp/uopz-src && phpize && ./configure && make -j"${ZEALPHP_BUILD_JOBS}" && make install)
            rm -rf /tmp/uopz-src
        fi
        docker-php-ext-enable --ini-name zz-uopz.ini uopz
    fi

    {
        echo "short_open_tag=On"
        echo "memory_limit=1024M"
    } > /usr/local/etc/php/conf.d/99-zealphp.ini

    mkdir -p /var/lib/php/sessions
    chmod 1733 /var/lib/php/sessions

    php -m | grep -q '^sockets$'
    php -m | grep -q '^openswoole$'
    php -m | grep -qE '^(zealphp|uopz)$'

    echo -e "${GREEN}Docker image dependencies installed successfully.${RESET}"
}

if [ "${1:-}" = "--docker" ]; then
    docker_setup
    exit 0
fi

# Function to check if the script is being run as root
# Returns 0 if the script is run as root, 1 otherwise
is_root() {
    if [ "$EUID" -ne 0 ]; then
        echo -e "${RED}Please run as root.${RESET}"
        return 1 # Not root
    fi
    # When already root (Docker, `curl | sudo bash`), sudo may not be installed.
    SUDO=""
    if [ "$EUID" -ne 0 ] && command -v sudo >/dev/null 2>&1; then
        SUDO="sudo"
    fi
    # Suppress interactive apt prompts (tzdata, etc.) — required for
    # `curl … | sudo bash` and for fresh Docker images where the timezone
    # dialog would otherwise hang the install.
    export DEBIAN_FRONTEND=noninteractive
    export TZ="${TZ:-Etc/UTC}"
    return 0 # Root
}

# Function to print welcome message
print_welcome_message() {
    echo -e "${GREEN}         ========================================================================${RESET}"
    echo -e "${YELLOW}         Welcome to ZealPHP - An open-source PHP framework powered by OpenSwoole${RESET}"
    echo -e "${GREEN}         ========================================================================${RESET}"

    echo -e "\n"

    echo -e "${MAGENTA}ZealPHP offers a lightweight, high-performance alternative to Next.js,${RESET}"
    echo -e "${MAGENTA}leveraging OpenSwoole’s asynchronous I/O to perform everything Next.js can and much more.${RESET}"
    echo -e "${MAGENTA}Unlock the full potential of PHP with ZealPHP and OpenSwoole's speed and scalability!${RESET}"

    echo -e "\n"

    echo -e "${WHITE}Features:${RESET}"
    echo -e "${WHITE}1. Dynamic HTML Streaming with APIs and Sockets${RESET}"
    echo -e "${WHITE}2. Parallel Data Fetching and Processing (Use go() to run async coroutine)${RESET}"
    echo -e "${WHITE}3. Dynamic Routing Tree with Implicit Routes for Public and API${RESET}"
    echo -e "${WHITE}4. Programmable and Injectable Routes for Authentication${RESET}"
    echo -e "${WHITE}5. Dynamic and Nested Templating and HTML Rendering${RESET}"
    echo -e "${WHITE}6. Workers, Tasks and Processes${RESET}"
    echo -e "${WHITE}7. All PHP Superglobals are constructed per request${RESET}"

    echo -e "\n"

    echo -e "${YELLOW}This script will set up the PHP environment for ZealPHP.${RESET}"
    echo -e "${YELLOW}Please wait while the setup is in progress... This may take a few minutes.${RESET}"
    echo -e "${RED}For more information, visit: https://php.zeal.ninja ${RESET}"
}

# Function to get user confirmation for the setup
# Returns 0 if the user chooses to continue, 1 if the user chooses to abort
#
# Auto-confirms in non-interactive mode (no TTY on stdin), which is the
# normal case for `curl -fsSL ... | sudo bash` — stdin is the script
# stream, not a terminal, so blocking on `read` would deadlock.
# Set ZEALPHP_NO_PROMPT=1 to force auto-confirm even on a TTY.
get_confirmation() {
    if [ "${ZEALPHP_NO_PROMPT:-0}" = "1" ] || ! [ -t 0 ]; then
        echo -e "${YELLOW}Non-interactive mode: auto-confirming.${RESET}"
        echo -e "${YELLOW}Set ZEALPHP_NO_PROMPT=0 and run from a TTY to be prompted.${RESET}"
        return 0
    fi
    while true; do
        read -rp "Do you want to continue? (y/n): " choice
        case "$choice" in
        y | Y) return 0 ;;
        n | N)
            echo -e "${RED}Setup aborted.${RESET}"
            return 1
            ;;
        *) echo "Invalid choice. Please enter 'y' or 'n'." ;;
        esac
    done
}

# Function to update package lists
# Returns 0 if the update is successful, 1 if the update fails
update_package_lists() {
    echo -e "${YELLOW}Updating package lists.${RESET}"

    if ! $SUDO apt update; then
        echo -e "${RED}Failed to update package lists.${RESET}"
        return 1 # Return an error code if the update fails
    fi

    echo -e "${GREEN}Package lists updated successfully.${RESET}"
    return 0 # update is successful
}

# Function to install add-apt-repository [executed only if not already installed]
# Returns 0 if the command is already available or installation is successful, 1 if the installation fails
install_add_apt_repository() {
    if ! command -v add-apt-repository &>/dev/null; then
        echo -e "${YELLOW}Installing software-properties-common.${RESET}"
        $SUDO apt install -y software-properties-common || {
            echo -e "${RED}Failed to install software-properties-common.${RESET}"
            return 1 # Installation fails
        }
    fi
    return 0 # Command is already available
}

# Function to check PHP is installed and version is compatible or not.
# If not compatible returns 1 else returns 0
check_php_version() {
    local required_version="8.3"
    local current_version=$(php -r "echo PHP_VERSION;" 2>/dev/null)

    if [ -z "$current_version" ]; then
        echo -e "${RED}PHP is not installed.${RESET}"
        return 1 # Allow installation of PHP 8.3
    fi

    if [ "$(printf '%s\n' "$required_version" "$current_version" | sort -V | head -n1)" = "$required_version" ] && [ "$current_version" != "$required_version" ]; then
        echo -e "${GREEN}Current PHP version $current_version is sufficient.${RESET}"
        return 0 # Skip PHP installation
    else
        return 2 # PHP version is not compatible with ZealPHP
    fi
}

# Function to get User confirmation to
# 1. Remove current PHP version and install PHP 8.3 (returns 0)
# 2. Install PHP 8.3 without removing current PHP version (returns 0)
# 3. Abort the setup (due to incompatibility) (returns 1)
get_php_version_confirmation() {
    echo -e "${YELLOW}PHP version is not compatible.${RESET}"
    echo -e "${YELLOW}Minimum required PHP version is 8.3 (composer.json: \"php\": \"^8.3\").${RESET}"

    # Non-interactive: default to option 2 (install alongside, safest)
    if [ "${ZEALPHP_NO_PROMPT:-0}" = "1" ] || ! [ -t 0 ]; then
        echo -e "${YELLOW}Non-interactive mode: keeping existing PHP and installing 8.3 alongside.${RESET}"
        return 0
    fi

    echo -e "${YELLOW}Please choose one of the following options:${RESET}"
    echo -e "${YELLOW}1. Remove current PHP version and install PHP 8.3${RESET}"
    echo -e "${YELLOW}2. Install PHP 8.3 without removing current PHP version${RESET}"
    echo -e "${YELLOW}3. Abort the setup${RESET}"
    while true; do
        read -rp "Enter your choice (1/2/3): " choice
        case "$choice" in
        1)
            $SUDO apt purge -y "php*" || { # Remove all PHP packages if user agrees
                echo -e "${RED}Failed to remove PHP $current_version. Aborting setup.${RESET}"
                return 1 # Exit if removal fails
            }
            echo -e "${GREEN}PHP $current_version removed successfully.${RESET}"
            return 0 # Allow installation of PHP 8.3
            ;;
        2)
            return 0 # Allow installation of PHP 8.3
            ;;
        3)
            echo -e "${RED}Setup aborted due to incompatible PHP version.${RESET}"
            return 1 # Exit if the user declines
            ;;
        *)
            echo -e "${RED}Invalid choice. Please enter '1', '2' or '3'.${RESET}"
            ;;
        esac
    done
}

# Function to install PHP 8.3
# Returns 0 if the installation is successful, 
# Returns 1 if the repository addition fails and if the installation fails
install_php_8.3() {
    echo -e "${YELLOW}Installing PHP 8.3.${RESET}"

    echo -e "${GREEN}Adding Ondrej PHP repository.${RESET}"
    $SUDO add-apt-repository -y ppa:ondrej/php || {
        echo -e "${RED}Failed to add PHP repository.${RESET}"
        return 1 # The repository addition fails
    }

    update_package_lists || return 1

    $SUDO apt install -y php8.3 || {
        echo -e "${RED}Failed to install PHP 8.3.${RESET}"
        return 1 # Installation fails
    }

    return 0 # Installation is successful
}

# Funtion to Configure PHP path for PHP 8.3
# Returns 0 if the configuration is successful, 1 if the configuration fails
configure_php_path() {
    echo -e "${YELLOW}Configuring PHP path.${RESET}"
    $SUDO update-alternatives --set php /usr/bin/php8.3 || {
        echo -e "${RED}Failed to configure PHP path.${RESET}"
        return 1 # Configuration fails
    }
    php -v | grep -q 'PHP 8.3' && {
        echo -e "${GREEN}PHP path configured successfully.${RESET}"
        return 0 # Configuration is successful
    } || {
        echo -e "${RED}Failed to configure PHP path.${RESET}"
        return 1 # Configuration fails
    }
}

# Function to configure PHP extensions
# Returns 0 if the extension is configured successfully, 1 if failed to add extension to PHP config
configure_php_extension() {
    local extension=$1 # e.g., extension=openswoole.so

    # Get PHP configuration directory
    local config_dir=$(php --ini | grep "Scan for additional .ini files in" | awk '{print $7}')
    local config_file="${config_dir}/99-zealphp-openswoole.ini"

    echo -e "${YELLOW}Configuring PHP extension $extension.${RESET}"

    # Ensure the configuration file exists
    $SUDO touch "$config_file"

    # Check if the extension is already in the configuration file
    if grep -q "^$extension$" "$config_file"; then
        echo -e "${GREEN}PHP extension $extension is already configured in $config_file.${RESET}"
        return 0 # No action needed
    fi

    # Add the extension to the configuration file
    echo "$extension" | $SUDO tee -a "$config_file" >/dev/null || {
        echo -e "${RED}Failed to add $extension to $config_file.${RESET}"
        return 1 # Failed to add extension to PHP config
    }

    echo -e "${GREEN}PHP extension $extension configured successfully.${RESET}"
    return 0 # Extension is configured successfully
}

# Function to install required packages for OpenSwoole and development tools
# Returns 0 if the installation is successful, 1 if the installation fails or enabling MySQL extension fails
install_dependencies() {
    local php_version=$(php -r "echo PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;" 2>/dev/null)

    echo -e "${YELLOW}Installing Main requirements for OpenSwoole and useful packages.${RESET}"

    local packages=(
        "gcc" 
        "php${php_version}-dev" 
        "php${php_version}-cli" 
        "php${php_version}-common" 
        "php${php_version}-mbstring" 
        "php${php_version}-xml" 
        "php${php_version}-curl"
        "php${php_version}-intl"
        "php${php_version}-sqlite3"
        "php${php_version}-mysqli"
        "php${php_version}-zip"
        "openssl"
        "libssl-dev" 
        "curl" 
        "libcurl4-openssl-dev" 
        "libpcre3-dev"
        "build-essential"
        "autoconf"
        "automake"
        "libtool"
        "git"
        "postgresql"
        "libpq-dev"
        "libc-ares-dev"
        "libnghttp2-dev"
        "libbrotli-dev"
        "zlib1g-dev"
        "pkg-config"
        "ca-certificates"
    )

    $SUDO apt install -y "${packages[@]}" || {
        echo -e "${RED}Failed to install dependencies.${RESET}"
        return 1 # Installation fails
    }

    # Ensure the MySQL extension is enabled
    $SUDO phpenmod mysqli || {
        echo -e "${RED}Failed to enable the MySQL extension.${RESET}"
        return 1 # Enabling fails
    }

    echo -e "${GREEN}Dependencies installed successfully.${RESET}"
    return 0 # Installation is successful
}

# Function to check and remove OpenSwoole if installed
# Returns 0 if the removal is successful, 1 if the removal fails or disabling the PHP extension fails 
check_and_remove_openswoole() {
    echo -e "${YELLOW}Checking if OpenSwoole is installed.${RESET}"

    # Check and remove installation via apt
    if dpkg -l | grep -q 'php-openswoole'; then
        echo -e "${GREEN}OpenSwoole is installed via apt. Removing.${RESET}"
        $SUDO apt remove -y php-openswoole || {
            echo -e "${RED}Failed to remove OpenSwoole installed via apt.${RESET}"
            return 1 # removal fails
        }
    fi

    # Check and remove installation via pecl (pecl may be absent — we no longer
    # install via it; guard so a missing pecl isn't a noisy "command not found").
    if command -v pecl >/dev/null 2>&1 && pecl list 2>/dev/null | grep -q 'openswoole'; then
        echo -e "${GREEN}OpenSwoole is installed via pecl. Removing.${RESET}"
        pecl uninstall openswoole || {
            echo -e "${RED}Failed to remove OpenSwoole installed via pecl.${RESET}"
            return 1 # removal fails
        }
    fi

    # Check and disable PHP extension if loaded
    if php -m | grep -q '^swoole$'; then
        echo -e "${GREEN}OpenSwoole PHP extension is loaded. Disabling.${RESET}"
        $SUDO phpdismod openswoole || {
            echo -e "${RED}Failed to disable OpenSwoole PHP extension.${RESET}"
            return 1 # disabling fails
        }
    fi

    echo -e "${GREEN}OpenSwoole check and removal completed.${RESET}"
    return 0 # removal is successful
}

# Function to install OpenSwoole via PECL with specific configurations
# Returns 0 if the installation is successful, 1 if the installation fails
install_openswoole() {
    echo -e "${YELLOW}Installing OpenSwoole with custom configurations.${RESET}"

    build_install_openswoole || {
        echo -e "${RED}Failed to install OpenSwoole (source build).${RESET}"
        return 1 # Installation fails
    }

    # The source build installed openswoole.so but did not enable it. Enable it
    # now and verify it actually loads.
    configure_php_extension "extension=openswoole.so" || return 1
    if ! php -r 'exit(extension_loaded("openswoole") ? 0 : 1);'; then
        echo -e "${RED}OpenSwoole built but failed to load (is ext-sockets available?).${RESET}"
        return 1
    fi

    echo -e "${GREEN}OpenSwoole installed.${RESET}"
    return 0 # Installation is successful
}

# Function to check and remove uopz if installed
# Returns 0 if the removal is successful, 1 if the removal fails or disabling the PHP extension fails
check_and_remove_uopz() {
    echo -e "${YELLOW}Checking if uopz is installed.${RESET}"

    # Check if uopz is installed via apt
    if dpkg -l | grep -q 'php-uopz'; then
        echo -e "${YELLOW}uopz is installed via apt. Removing.${RESET}"

        # Remove uopz installed via apt
        $SUDO apt remove -y php-uopz || {
            echo -e "${RED}Failed to remove uopz installed via apt.${RESET}"
            return 1 # removal fails
        }
    fi

    # Check if uopz is installed via pecl
    if pecl list | grep -q 'uopz'; then
        echo -e "${YELLOW}uopz is installed via pecl.Removing${RESET}"

        # Remove uopz installed via pecl
        pecl uninstall uopz || {
            echo -e "${RED}Failed to remove uopz installed via pecl.${RESET}"
            return 1 # removal fails
        }
    fi

    # Check if uopz PHP extension is loaded
    if php -m | grep -q '^uopz$'; then
        echo -e "${RED}uopz PHP extension is loaded. Disabling.${RESET}"
        $SUDO phpdismod uopz || {
            echo -e "${RED}Failed to disable uopz PHP extension.${RESET}"
            return 1 # disabling fails
        }
    fi

    echo -e "${GREEN}uopz check completed.${RESET}"
    return 0 # removal is successful
}

# Function to install ext-zealphp (ZealPHP's own extension).
# Falls back to uopz if ext-zealphp build fails.
# Returns 0 if installation is successful, 1 if both fail.
install_zealphp_ext() {
    echo -e "${YELLOW}Installing ext-zealphp (ZealPHP's function-override extension)${RESET}"

    local tmpdir
    tmpdir="$(mktemp -d)"
    git clone --depth 1 --branch "${ZEALPHP_EXT_VERSION:-v0.3.52}" https://github.com/sibidharan/ext-zealphp.git "$tmpdir/ext-zealphp" || {
        echo -e "${YELLOW}Failed to clone ext-zealphp repo. Falling back to uopz.${RESET}"
        rm -rf "$tmpdir"
        install_uopz_fallback
        return $?
    }

    if (cd "$tmpdir/ext-zealphp" && phpize && ./configure --enable-zealphp && make -j"${ZEALPHP_BUILD_JOBS}" && $SUDO make install); then
        rm -rf "$tmpdir"
        echo -e "${GREEN}ext-zealphp built and installed.${RESET}"
        return 0
    fi

    echo -e "${YELLOW}ext-zealphp build failed. Falling back to uopz.${RESET}"
    rm -rf "$tmpdir"
    install_uopz_fallback
    return $?
}

install_uopz_fallback() {
    echo -e "${YELLOW}Installing uopz as fallback${RESET}"
    if $SUDO pecl install uopz 2>/dev/null; then
        echo -e "${GREEN}uopz installed via PECL.${RESET}"
        return 0
    fi
    echo -e "${YELLOW}PECL uopz failed. Building from git source.${RESET}"
    local tmpdir
    tmpdir="$(mktemp -d)"
    git clone --depth 1 https://github.com/krakjoe/uopz.git "$tmpdir" || {
        echo -e "${RED}Failed to clone uopz from GitHub.${RESET}"
        rm -rf "$tmpdir"
        return 1
    }
    (cd "$tmpdir" && phpize && ./configure && make -j"${ZEALPHP_BUILD_JOBS}" && $SUDO make install) || {
        echo -e "${RED}Failed to build uopz from source.${RESET}"
        rm -rf "$tmpdir"
        return 1
    }
    rm -rf "$tmpdir"
    echo -e "${GREEN}uopz built and installed from source (fallback).${RESET}"
    return 0
}

# Function to check if Composer is installed
# Returns 0 if Composer is installed, 1 if Composer is not installed
check_composer_installed() {
    if command -v composer >/dev/null; then
        echo -e "${YELLOW}Composer is already installed.${RESET}"
        composer --version
        return 0 # Composer is already installed
    else
        return 1 # Composer is not installed
    fi
}

# Function to check and install Composer
# Returns 0 if the installation is successful, 1 if the installation fails
install_composer() {
    echo "Installing Composer using apt."

    $SUDO apt install -y composer || {
        echo "Failed to install Composer."
        return 1 # Installation fails
    }

    # Verify Composer installation
    if command -v composer >/dev/null; then
        echo -e "${GREEN}Composer installed successfully.${RESET}"
        composer --version
        return 0 # Composer is installed successfully
    else
        echo -e "${RED}Composer installation failed.${RESET}"
        return 1 # Composer installation fails
    fi
}

# Function to print the final message
final_message() {
    echo -e "${GREEN}Setup completed successfully.${RESET}"
    echo -e "${YELLOW}You can now start using ZealPHP.${RESET}"
    echo -e "${RED}For more information, visit: https://php.zeal.ninja ${RESET}"
}

# Function to install ZealPHP dependencies on macOS via Homebrew.
# Requires Homebrew (brew.sh) to be installed already.
macos_setup() {
    set -e

    echo -e "${YELLOW}Detected macOS. Using Homebrew install path.${RESET}"

    if ! command -v brew >/dev/null; then
        echo -e "${RED}Homebrew not found. Install it from https://brew.sh first, then re-run.${RESET}"
        return 1
    fi

    echo -e "${GREEN}Installing PHP 8.3 (or newer) via Homebrew.${RESET}"
    brew install php pkg-config autoconf automake libtool c-ares nghttp2 brotli composer || {
        echo -e "${RED}brew install failed. See output above.${RESET}"
        return 1
    }

    # Detect the PHP that brew just exposed
    local php_bin="$(command -v php)"
    local pecl_bin="$(command -v pecl)"
    local php_ini_dir
    php_ini_dir="$($php_bin -i | awk -F'=> ' '/Scan this dir for additional .ini files/ {print $2}' | head -1 | tr -d ' ')"

    echo -e "${YELLOW}PHP binary: ${php_bin}${RESET}"
    echo -e "${YELLOW}PHP ini dir: ${php_ini_dir}${RESET}"

    if [ -z "$php_ini_dir" ] || [ ! -d "$php_ini_dir" ]; then
        echo -e "${RED}Could not locate PHP additional ini directory. Aborting.${RESET}"
        return 1
    fi

    echo -e "${GREEN}Installing OpenSwoole (tagged source build).${RESET}"
    build_install_openswoole || {
        echo -e "${RED}OpenSwoole source build failed.${RESET}"
        return 1
    }
    # The source build installed openswoole.so but did not enable it; write a
    # late-sorting ini so it loads after the (built-in) sockets extension.
    {
        echo "extension=openswoole.so"
        echo "short_open_tag=On"
    } > "${php_ini_dir}/zz-openswoole.ini"
    "$php_bin" -r 'exit(extension_loaded("openswoole") ? 0 : 1);' || {
        echo -e "${RED}OpenSwoole built but failed to load.${RESET}"
        return 1
    }

    echo -e "${GREEN}Installing ext-zealphp.${RESET}"
    local tmpdir
    tmpdir="$(mktemp -d)"
    if git clone --depth 1 --branch "${ZEALPHP_EXT_VERSION:-v0.3.52}" https://github.com/sibidharan/ext-zealphp.git "$tmpdir" && \
       (cd "$tmpdir" && phpize && ./configure --enable-zealphp && make -j"${ZEALPHP_BUILD_JOBS}" && make install); then
        echo "extension=zealphp.so" > "${php_ini_dir}/zz-zealphp.ini"
        rm -rf "$tmpdir"
        echo -e "${GREEN}ext-zealphp built and installed.${RESET}"
    else
        echo -e "${YELLOW}ext-zealphp failed. Falling back to uopz.${RESET}"
        rm -rf "$tmpdir"
        tmpdir="$(mktemp -d)"
        if ! "$pecl_bin" install uopz 2>/dev/null; then
            git clone --depth 1 https://github.com/krakjoe/uopz.git "$tmpdir" || { rm -rf "$tmpdir"; return 1; }
            (cd "$tmpdir" && phpize && ./configure && make -j"${ZEALPHP_BUILD_JOBS}" && make install) || { rm -rf "$tmpdir"; return 1; }
            rm -rf "$tmpdir"
        fi
        echo "extension=uopz.so" > "${php_ini_dir}/zz-uopz.ini"
    fi

    echo -e "${YELLOW}Verifying extensions.${RESET}"
    if "$php_bin" -m | grep -qE 'openswoole|zealphp'; then
        echo -e "${GREEN}OpenSwoole and ext-zealphp are loaded.${RESET}"
    else
        echo -e "${RED}Extensions not loaded — check ${php_ini_dir} for the .ini files.${RESET}"
        return 1
    fi

    final_message
    return 0
}

# Main Script

# macOS path — Homebrew based, separate from the Debian/Ubuntu apt flow below.
if [ "$(uname -s)" = "Darwin" ]; then
    macos_setup || exit 1
    exit 0
fi

# Detect Linux distribution and bail with helpful guidance on unsupported envs.
# Without this, the apt flow below on RHEL/Arch/Alpine produced confusing
# "apt-get: command not found" errors with no hint of where to go next.
if [ "$(uname -s)" != "Linux" ]; then
    echo -e "${RED}Unsupported OS: $(uname -s).${RESET}"
    echo -e "${YELLOW}setup.sh currently supports:${RESET}"
    echo -e "  - macOS (via Homebrew)"
    echo -e "  - Linux: Ubuntu / Debian (and apt-based derivatives)"
    echo -e ""
    echo -e "For Windows, use WSL2 with Ubuntu and re-run setup.sh inside it."
    echo -e "Manual install steps: https://php.zeal.ninja/getting-started#install"
    exit 1
fi

DISTRO_ID=""
DISTRO_NAME="$(uname -s)"
if [ -r /etc/os-release ]; then
    # shellcheck disable=SC1091
    . /etc/os-release
    DISTRO_ID="${ID:-}"
    DISTRO_NAME="${PRETTY_NAME:-$ID}"
fi

case "$DISTRO_ID" in
    ubuntu|debian|linuxmint|pop|elementary|kali|raspbian|neon)
        : # supported via apt
        ;;
    "")
        echo -e "${YELLOW}Could not detect distribution (no /etc/os-release).${RESET}"
        echo -e "${YELLOW}Assuming Debian/Ubuntu — apt commands will be attempted.${RESET}"
        ;;
    *)
        echo -e "${RED}Unsupported Linux distribution: ${DISTRO_NAME}.${RESET}"
        echo -e "${YELLOW}setup.sh's automated apt flow runs on Ubuntu, Debian, and their derivatives.${RESET}"
        echo -e ""
        echo -e "For ${DISTRO_NAME}, please install the following manually:"
        echo -e "  ${WHITE}1.${RESET} PHP 8.3+ (cli + dev headers)"
        echo -e "  ${WHITE}2.${RESET} ${WHITE}ext-openswoole${RESET} from source (git clone the tag + phpize/configure/make)"
        echo -e "  ${WHITE}3.${RESET} ${WHITE}ext-uopz${RESET} via PECL"
        echo -e "  ${WHITE}4.${RESET} composer"
        echo -e ""
        echo -e "Detailed steps: ${MAGENTA}https://php.zeal.ninja/getting-started#install${RESET}"
        echo -e "Or use Docker: ${MAGENTA}docker compose up app${RESET} from a cloned checkout."
        exit 1
        ;;
esac

if ! command -v apt-get >/dev/null 2>&1; then
    echo -e "${RED}apt-get not found on this system, but distribution detected as ${DISTRO_NAME}.${RESET}"
    echo -e "${YELLOW}setup.sh requires apt-get for the Linux flow. If you're on a minimal image, install it first.${RESET}"
    exit 1
fi

is_root || exit 1

print_welcome_message

get_confirmation || exit 1
update_package_lists || exit 1

install_add_apt_repository || exit 1

check_php_version
php_version_status=$?

if [ $php_version_status -eq 1 ]; then
    install_php_8.3 || exit 1
    configure_php_path || exit 1
elif [ $php_version_status -eq 2 ]; then
    get_php_version_confirmation || exit 1
    install_php_8.3 || exit 1
    configure_php_path || exit 1
fi

if ! install_dependencies; then exit 1; fi

if check_and_remove_openswoole; then
    if install_openswoole; then
        # OpenSwoole is enabled inside install_openswoole (source build + enable +
        # verify); only the short_open_tag ini tweak remains here.
        configure_php_extension "short_open_tag=on"
    else
        exit 1
    fi
fi

if check_and_remove_uopz; then
    if install_zealphp_ext; then
        if [ -f "$(php -i 2>/dev/null | awk -F'=> ' '/^extension_dir/ {print $2}' | head -1 | tr -d ' ')/zealphp.so" ]; then
            configure_php_extension "extension=zealphp.so"
        else
            configure_php_extension "extension=uopz.so"
        fi
    else
        exit 1
    fi
fi

if ! check_composer_installed; then
    if ! install_composer; then exit 1; fi
fi

# clear
final_message
