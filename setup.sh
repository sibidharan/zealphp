#!/bin/bash

# Function to check if add-apt-repository command is available
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

# Function to check if PHP 7.4 or above is installed
check_php_version() {
    local required_version="7.4"
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
    local php_version=$(php -r "echo PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;" 2>/dev/null)

    echo "Installing required packages for PHP $php_version..."
    sudo apt install -y gcc php-dev openssl libssl-dev curl libcurl4-openssl-dev \
        libpcre3-dev build-essential php${php_version}-mysql postgresql libpq-dev || {
        echo "Failed to install dependencies."; exit 1;
    }

    # Ensure the MySQL extension is enabled
    sudo phpenmod mysql || {
        echo "Failed to enable the MySQL extension."; exit 1;
    }

    echo "Dependencies installed successfully."
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

    # Install OpenSwoole with required options automatically (coroutine sockets, OpenSSL, etc.)
    echo -e "yes\nyes\nyes\nyes\nyes\nyes" | sudo pecl install openswoole || { echo "Failed to install OpenSwoole."; exit 1; }

    # Get PHP config directory and configure OpenSwoole
    local config_dir=$(php --ini | grep "Scan for additional .ini files in" | awk '{print $7}')
    local config_file="${config_dir}/99-zealphp-openswoole.ini"

    echo "extension=openswoole.so" | sudo tee "$config_file" > /dev/null
    echo "short_open_tag=on" | sudo tee -a "$config_file" > /dev/null
    echo "swoole.use_shortname=off" | sudo tee -a "$config_file" > /dev/null
    echo "Enable coroutine sockets, OpenSSL, HTTP2, etc."

    # Ensure OpenSwoole settings are enabled correctly
    sudo sed -i 's/^enable_coroutine_sockets.*/enable_coroutine_sockets=yes/' "$config_file"
    sudo sed -i 's/^enable_openssl.*/enable_openssl=yes/' "$config_file"
    sudo sed -i 's/^enable_http2.*/enable_http2=yes/' "$config_file"
    sudo sed -i 's/^enable_coroutine_mysqlnd.*/enable_coroutine_mysqlnd=yes/' "$config_file"
    sudo sed -i 's/^enable_coroutine_curl.*/enable_coroutine_curl=yes/' "$config_file"
    sudo sed -i 's/^enable_coroutine_postgres.*/enable_coroutine_postgres=yes/' "$config_file"

    echo "OpenSwoole installed and configured successfully with the required options."
}

# Function to configure existing OpenSwoole with the required options
configure_existing_openswoole() {
    echo "Configuring existing OpenSwoole..."

    # Get PHP config directory dynamically
    local config_dir=$(php --ini | grep "Scan for additional .ini files in" | awk '{print $7}')
    local config_file="${config_dir}/99-zealphp-openswoole.ini"

    # Ensure OpenSwoole settings are enabled correctly
    echo "Ensuring necessary settings are enabled in OpenSwoole config..."
    sudo sed -i 's/^enable_coroutine_sockets.*/enable_coroutine_sockets=yes/' "$config_file"
    sudo sed -i 's/^enable_openssl.*/enable_openssl=yes/' "$config_file"
    sudo sed -i 's/^enable_http2.*/enable_http2=yes/' "$config_file"
    sudo sed -i 's/^enable_coroutine_mysqlnd.*/enable_coroutine_mysqlnd=yes/' "$config_file"
    sudo sed -i 's/^enable_coroutine_curl.*/enable_coroutine_curl=yes/' "$config_file"
    sudo sed -i 's/^enable_coroutine_postgres.*/enable_coroutine_postgres=yes/' "$config_file"

    echo "OpenSwoole configuration updated successfully."
}

# Function to check if uopz is already installed
check_uopz_installed() {
    if php -m | grep -q 'uopz'; then
        echo "uopz is already installed."
        return 0
    else
        echo "uopz is not installed. Proceeding with installation..."
        return 1
    fi
}

# Function to install uopz via PECL
install_uopz() {
    echo "Installing uopz..."

    sudo pecl install uopz || { echo "Failed to install uopz."; exit 1; }

    # Get PHP config directory and configure uopz
    local config_dir=$(php --ini | grep "Scan for additional .ini files in" | awk '{print $7}')
    local config_file="${config_dir}/99-zealphp-openswoole.ini"

    echo "extension=uopz.so" | sudo tee -a "$config_file" > /dev/null

    echo "uopz installed and configured successfully."
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


# Pre-information for the user
echo -e "\e[1;33m         Welcome to ZealPHP - An open-source PHP framework powered by OpenSwoole.\e[0m"

echo -e "\n"

echo -e "\e[1;35mZealPHP offers a lightweight, high-performance alternative to Next.js,\e[0m"
echo -e "\e[1;35mleveraging OpenSwoole’s asynchronous I/O to perform everything Next.js can and much more.\e[0m"
echo -e "\e[1;35mUnlock the full potential of PHP with ZealPHP and OpenSwoole's speed and scalability!\e[0m"


echo -e "\n"

echo -e "\e[1;37mFeatures:\e[0m"
echo -e "\e[1;37m1. Dynamic HTML Streaming with APIs and Sockets\e[0m"
echo -e "\e[1;37m2. Parallel Data Fetching and Processing (Use go() to run async coroutine)\e[0m"
echo -e "\e[1;37m3. Dynamic Routing Tree with Implicit Routes for Public and API\e[0m"
echo -e "\e[1;37m4. Programmable and Injectable Routes for Authentication\e[0m"
echo -e "\e[1;37m5. Dynamic and Nested Templating and HTML Rendering\e[0m"
echo -e "\e[1;37m6. Workers, Tasks and Processes\e[0m"
echo -e "\e[1;37m7. All PHP Superglobals are constructed per request\e[0m"

echo -e "\n"

echo -e "\e[1;33mThis script will set up the PHP environment for ZealPHP.\e[0m"
echo -e "\e[1;33mPlease wait while the setup is in progress... This may take a few minutes.\e[0m"
echo -e "\e[1;31mFor more information, visit: https://php.zeal.ninja\e[0m"



# Prompt for user confirmation
while true; do
    read -rp "Do you want to continue with the setup? (y/n): " confirm

    case "$confirm" in
        y|Y)
            echo "Proceeding with the setup..."
            break
            ;;
        n|N)
            echo -e "\e[1;31mSetup aborted.\e[0m"
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
else
    echo "PHP is already installed. Ensuring dependencies and OpenSwoole are available."
fi

install_dependencies

# Check and install OpenSwoole, if already installed, configure it
if check_openswoole_installed; then
    echo "OpenSwoole is already installed. Configuring..."
    configure_existing_openswoole
else
    install_openswoole
    configure_openswoole
fi

# Check and install uopz
if ! check_uopz_installed; then
    install_uopz
fi

# Check and install Composer
if ! check_composer_installed; then
    install_composer
fi

# clear the terminal
clear

# Final message
echo -e "\e[1;32mSetup completed successfully!\e[0m"
echo -e "\e[1;33mFeel free to explore all features in ZealPHP.\e[0m"
echo -e "\e[1;34mVisit https://php.zeal.ninja to explore ZealPHP.\e[0m"