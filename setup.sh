#!/bin/bash

# Color codes for output messages
RED="\e[1;31m"
GREEN="\e[1;32m"
YELLOW="\e[1;33m"
MAGENTA="\e[1;35m"
WHITE="\e[1;37m"
RESET="\e[0m"

# Function to check if the script is being run as root
# Returns 0 if the script is run as root, 1 otherwise
is_root() {
    if [ "$EUID" -ne 0 ]; then
        echo -e "${RED}Please run as root.${RESET}"
        return 1 # Not root
    fi
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
get_confirmation() {
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

    if ! sudo apt update; then
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
        sudo apt install -y software-properties-common || {
            echo -e "${RED}Failed to install software-properties-common.${RESET}"
            return 1 # Installation fails
        }
    fi
    return 0 # Command is already available
}

# Function to check PHP is installed and version is compatible or not.
# If not compatible returns 1 else returns 0
check_php_version() {
    local required_version="8.1"
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
    echo -e "${YELLOW}Minimum required PHP version is 7.4.${RESET}"

    echo -e "${YELLOW}Please choose one of the following options:${RESET}"
    echo -e "${YELLOW}1. Remove current PHP version and install PHP 8.3${RESET}"
    echo -e "${YELLOW}2. Install PHP 8.3 without removing current PHP version${RESET}"
    echo -e "${YELLOW}3. Abort the setup${RESET}"
    while true; do
        read -rp "Enter your choice (1/2/3): " choice
        case "$choice" in
        1)
            sudo apt purge -y "php*" || { # Remove all PHP packages if user agrees
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
    sudo add-apt-repository -y ppa:ondrej/php || {
        echo -e "${RED}Failed to add PHP repository.${RESET}"
        return 1 # The repository addition fails
    }

    update_package_lists || return 1

    sudo apt install -y php8.3 || {
        echo -e "${RED}Failed to install PHP 8.3.${RESET}"
        return 1 # Installation fails
    }

    return 0 # Installation is successful
}

# Funtion to Configure PHP path for PHP 8.3
# Returns 0 if the configuration is successful, 1 if the configuration fails
configure_php_path() {
    echo -e "${YELLOW}Configuring PHP path.${RESET}"
    sudo update-alternatives --set php /usr/bin/php8.3 || {
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
    sudo touch "$config_file"

    # Check if the extension is already in the configuration file
    if grep -q "^$extension$" "$config_file"; then
        echo -e "${GREEN}PHP extension $extension is already configured in $config_file.${RESET}"
        return 0 # No action needed
    fi

    # Add the extension to the configuration file
    echo "$extension" | sudo tee -a "$config_file" >/dev/null || {
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
        "php${php_version}-mysqli" 
        "openssl" 
        "libssl-dev" 
        "curl" 
        "libcurl4-openssl-dev" 
        "libpcre3-dev" 
        "build-essential" 
        "postgresql" 
        "libpq-dev"
    )

    sudo apt install -y "${packages[@]}" || {
        echo -e "${RED}Failed to install dependencies.${RESET}"
        return 1 # Installation fails
    }

    # Ensure the MySQL extension is enabled
    sudo phpenmod mysqli || {
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
        sudo apt remove -y php-openswoole || {
            echo -e "${RED}Failed to remove OpenSwoole installed via apt.${RESET}"
            return 1 # removal fails
        }
    fi

    # Check and remove installation via pecl
    if pecl list | grep -q 'openswoole'; then
        echo -e "${GREEN}OpenSwoole is installed via pecl. Removing.${RESET}"
        pecl uninstall openswoole || {
            echo -e "${RED}Failed to remove OpenSwoole installed via pecl.${RESET}"
            return 1 # removal fails
        }
    fi

    # Check and disable PHP extension if loaded
    if php -m | grep -q '^swoole$'; then
        echo -e "${GREEN}OpenSwoole PHP extension is loaded. Disabling.${RESET}"
        sudo phpdismod openswoole || {
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

    # Install OpenSwoole via PECL with custom configurations
    pecl install --configureoptions 'enable-sockets="yes" enable-openssl="yes" enable-http2="yes" enable-mysqlnd="yes" enable-hook-curl="yes" enable-cares="yes" with-postgres="yes"' openswoole || {
        echo -e "${RED}Failed to install OpenSwoole.${RESET}"
        return 1 # Installation fails
    }

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
        sudo apt remove -y php-uopz || {
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
        sudo phpdismod uopz || {
            echo -e "${RED}Failed to disable uopz PHP extension.${RESET}"
            return 1 # disabling fails
        }
    fi

    echo -e "${GREEN}uopz check completed.${RESET}"
    return 0 # removal is successful
}

# Function to install uopz via PECL
# Returns 0 if the installation is successful, 1 if the installation fails
install_uopz() {
    echo -e "${YELLOW}Installing uopz${RESET}"

    sudo pecl install uopz || {
        echo -e "${RED}Failed to install uopz.${RESET}"
        return 1 # Installation fails
    }

    echo -e "${GREEN}uopz installed and configured successfully.${RESET}"
    return 0 # Installation is successful
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

    sudo apt install -y composer || {
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

# Main Script
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
        configure_php_extension "extension=openswoole.so"
        configure_php_extension "short_open_tag=on"
    else
        exit 1
    fi
fi

if check_and_remove_uopz; then
    if install_uopz; then
        configure_php_extension "extension=uopz.so"
    else
        exit 1
    fi
fi

if ! check_composer_installed; then
    if ! install_composer; then exit 1; fi
fi

# clear
final_message
