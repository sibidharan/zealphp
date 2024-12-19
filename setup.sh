#!/bin/bash

# Function to check add-apt-repository command is available 
check_app_apt_repository_installed(){
    if ! command -v add-apt-repository >/dev/null; then
        echo "add-apt-repository command is not available."
        return 1
    else
        echo "add-apt-repository command is already available."
        return 0
    fi
}

# Function to install software-properties-common for add-apt-repository command
install_add_apt_repository() {
    echo "Installing software-properties-common for add-apt-repository command..."
    sudo apt update || { echo "Failed to update package lists."; exit 1; }
    sudo apt install -y software-properties-common || { echo "Failed to install software-properties-common."; exit 1; }
    echo "Software-properties-common [for add_apt_repository] installed successfully."
    
}

# Function to check if PHP 8.3 is installed
check_php_version() {
    local required_version="8.3"
    local current_version=$(php -r "echo PHP_VERSION;" 2>/dev/null)

    if [ -z "$current_version" ]; then
        echo "PHP is not installed."
        return 1
    fi

    if [ "$(printf '%s\n' "$required_version" "$current_version" | sort -V | head -n1)" = "$required_version" ]; then
        echo "Current PHP version $current_version is sufficient."
        return 0  
    else
        echo "Current PHP version $current_version is not sufficient. Minimum version is $required_version."
        return 1
    fi
}

# Function to install PHP 8.3
install_php() {
    echo "Updating package lists..."
    sudo apt update || { echo "Failed to update package lists."; exit 1; }

    # Ensure add-apt-repository is available for adding PHP PPA
    if ! check_app_apt_repository_installed; then
        install_add_apt_repository
    fi
    

    echo "Adding Ondřej Surý PPA for PHP..."
    sudo add-apt-repository -y ppa:ondrej/php || { echo "Failed to add Ondřej Surý PPA."; exit 1; }

    echo "Installing PHP 8.3 and common extensions..."
    sudo apt install -y php8.3 php8.3-cli php8.3-common php8.3-curl php8.3-mbstring php8.3-mysql php8.3-xml || {
        echo "Failed to install PHP 8.3."; exit 1;
    }

    # Verify PHP 8.3 installation
    if command -v php8.3 > /dev/null; then
        echo "PHP 8.3 has been successfully installed."
        configure_php_path
    else
        echo "Failed to install PHP 8.3."
        exit 1
    fi
}

# Function to configure the PHP path for CLI usage
configure_php_path() {
    echo "Configuring the PHP path..."
    sudo update-alternatives --set php /usr/bin/php8.3 || { echo "Failed to configure PHP path."; exit 1; }

    # Verify the configuration
    if php -v | grep -q 'PHP 8.3'; then
        echo "PHP path configured successfully to point to PHP 8.3."
    else
        echo "Failed to configure the PHP path."
        exit 1
    fi
}

# Function to install required packages for OpenSwoole and development tools
install_dependencies() {
    echo "Installing required packages..."
    sudo apt install -y gcc php-dev openssl libssl-dev curl libcurl4-openssl-dev libpcre3-dev build-essential php8.3-mysqlnd postgresql libpq-dev || {
        echo "Failed to install dependencies."; exit 1;
    }
}

# Function to check if OpenSwoole is already installed
check_openswoole_installed() {
    if php -m | grep -q 'swoole'; then
        echo "OpenSwoole is already installed."
        return 0
    else
        echo "OpenSwoole is not installed. Proceeding with installation..."
        return 1
    fi
}

# Function to install OpenSwoole via PECL
install_openswoole() {
    echo "Installing OpenSwoole..."
    sudo pecl install openswoole-22.1.2 || { echo "Failed to install OpenSwoole."; exit 1; }
    local config_path="/etc/php/8.3/cli/conf.d/99-zealphp-openswoole.ini"
    echo "extension=openswoole.so" | sudo tee "$config_path" > /dev/null
    echo "short_open_tag=on" | sudo tee -a "$config_path" > /dev/null

    
    # Confirm installation of Swoole extension
    if php -m | grep -q openswoole; then
        echo "OpenSwoole installed successfully."
    else
        echo "OpenSwoole installation failed."
        exit 1
    fi
}

# Function to check if Composer is installed
check_composer_installed() {
    if command -v composer >/dev/null; then
        echo "Composer is already installed."
        composer --version
        return 0
    else
        return 1
    fi
}

# Function to check and install Composer
install_composer() {
    echo "Installing Composer using apt..."
    sudo apt update || { echo "Failed to update package lists."; exit 1; }
    sudo apt install -y composer || { echo "Failed to install Composer."; exit 1; }

    # Verify Composer installation
    if command -v composer >/dev/null; then
        echo "Composer installed successfully."
        composer --version
    else
        echo "Composer installation failed."
        exit 1
    fi
}


#!/bin/bash

# Pre-information for the user
echo "============================================================================"
echo "                         ZealPHP Setup Script"
echo "============================================================================"
echo "This script will set up the PHP environment for ZealPHP."
echo "Please wait while the setup is in progress... This may take a few minutes."
echo "For more information, visit: php.zeal.ninja"
echo "============================================================================"

# Prompt for user confirmation
while true; do
    read -rp "Do you want to continue with the setup? (y/n): " confirm

    case "$confirm" in
        y|Y)
            echo "Proceeding with the setup..."
            break
            ;;
        n|N)
            echo "Setup aborted."
            exit 1
            ;;
        *)
            echo "Invalid input. Please enter 'y' for yes or 'n' for no."
            ;;
    esac
done



# Main script execution
if ! check_php_version; then
    install_php
fi

install_dependencies


#check and install OpenSwoole
if ! check_openswoole_installed; then
    install_openswoole
fi

# Check and install Composer
if ! check_composer_installed; then
    install_composer
fi

echo "Setup completed successfully!"
echo "Feel free to explore all features in zealphp."
echo "Visit php.zeal.ninja for more information."

