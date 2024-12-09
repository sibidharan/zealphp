sudo apt install libcurl4-openssl-dev
sudo pecl install openswoole-22.1.2
sudo sh -c 'echo "\nextension=openswoole.so" >> /etc/php/8.3/cli/conf.d/php-override.ini'

# confirm installation
php -m | grep swoole
if [ $? -eq 0 ]; then
    echo "Swoole installed successfully"
else
    echo "Swoole installation failed"
fi
```