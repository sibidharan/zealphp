#!/bin/bash

# Function to check if PHP 8.3 is installed
check_php_version() {
    if command -v php8.3 > /dev/null; then
        echo "PHP 8.3 is already installed."
        return 0
    else
        echo "PHP 8.3 is not installed. Proceeding with installation..."
        return 1
    fi
}

# Function to install PHP 8.3
install_php() {
    
    echo "Updating package lists..."
    sudo apt update
    echo "Adding Ondřej Surý PPA for PHP..."
    sudo add-apt-repository -y ppa:ondrej/php
    echo "Installing PHP 8.3 and common extensions..."
    sudo apt install -y php8.3 php8.3-cli php8.3-common php8.3-curl php8.3-mbstring php8.3-mysql php8.3-xml

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
    sudo update-alternatives --set php /usr/bin/php8.3

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
    sudo apt install -y gcc php-dev openssl libssl-dev curl libcurl4-openssl-dev libpcre3-dev build-essential postgresql libpq-dev
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
    sudo pecl install openswoole-22.1.2
    echo "extension=openswoole.so" | sudo tee -a /etc/php/8.3/cli/conf.d/00-openswoole.ini > /dev/null
    echo "short_open_tag=on" | sudo tee -a /etc/php/8.3/cli/conf.d/00-openswoole.ini > /dev/null
    sudo systemctl restart apache2

    # Confirm installation of Swoole extension
    php -m | grep -q swoole
    if [ $? -eq 0 ]; then
        echo "OpenSwoole installed and enabled."
    else
        echo "OpenSwoole installation failed."
        exit 1
    fi
}

# Check if PHP 8.3 is installed; if not, proceed with installation.
if ! check_php_version; then
    install_php
fi

# Install dependencies required for OpenSwoole.
install_dependencies

# Check and install OpenSwoole if not already installed.
if ! check_openswoole_installed; then
    install_openswoole
fi

echo "Setup completed successfully!"