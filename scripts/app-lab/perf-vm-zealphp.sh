#!/bin/bash
# Builds a ZealPHP + WordPress container on a clean perf VM and runs
# the matched benchmark against the same `perf-mysql` container that
# perf-vm-bench.sh provisioned for Apache.
#
# Pre-req: perf-vm-bench.sh has already run (Apache baseline captured,
# perf-mysql + /tmp/wp-perf already provisioned).
set -e

cd /tmp

# Stop any previous run
docker rm -f perf-zealphp 2>/dev/null || true

# Dockerfile — PHP 8.3 NTS + OpenSwoole + ext-zealphp + WP, served by ZealPHP
mkdir -p /tmp/perf-zealphp-build
cat > /tmp/perf-zealphp-build/Dockerfile << 'EOF'
FROM php:8.3-cli

RUN apt-get update && apt-get install -y -qq --no-install-recommends \
    git curl unzip libssl-dev libcurl4-openssl-dev libbrotli-dev \
    libnghttp2-dev libpq-dev autoconf build-essential pkg-config \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install pdo_mysql mysqli sockets >/dev/null

# OpenSwoole
RUN pecl install openswoole >/dev/null && docker-php-ext-enable openswoole

# Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

WORKDIR /app
EOF

docker build -t zealphp-perf:latest /tmp/perf-zealphp-build/

# Get ZealPHP source onto the host (assumes it's already scp'd to /tmp/zealphp)
if [ ! -d /tmp/zealphp ]; then
  echo "ERROR: /tmp/zealphp not found. Run: scp -r local-zealphp-checkout labs@<host>:/tmp/zealphp"
  exit 1
fi

# Build ext-zealphp inside a container
docker run --rm -v /tmp/zealphp/ext/zealphp:/src zealphp-perf:latest sh -c "
cd /src && phpize && ./configure --enable-zealphp && make -j$(nproc)
cp modules/zealphp.so /src/zealphp.so
"

# Compose final image with framework + WP + ext-zealphp
cat > /tmp/perf-zealphp-run.sh << 'EOF'
#!/bin/bash
# Inside the container — start ZealPHP serving WordPress
cp /src/zealphp.so $(php -r "echo ini_get('extension_dir');")/
echo "extension=zealphp" >> /usr/local/etc/php/conf.d/zealphp.ini
cd /app
composer install --no-dev --no-interaction --quiet
ZEALPHP_PORT=8080 php app.php
EOF
chmod +x /tmp/perf-zealphp-run.sh

# Spin up ZealPHP container — mounts WP at /apps/wordpress
docker run -d --name perf-zealphp --network perfnet \
  -v /tmp/zealphp:/app \
  -v /tmp/wp-perf:/apps/wordpress \
  -v /tmp/perf-zealphp-build:/src \
  -v /tmp/perf-zealphp-run.sh:/run.sh \
  -p 8085:8080 \
  --entrypoint /run.sh \
  zealphp-perf:latest

sleep 10
echo "==> ZealPHP probe:"
curl -sI --max-time 5 http://localhost:8085/ | head -3

echo ""
echo "==> Benchmark ZealPHP (Mode 5 coroutine — default)"
for i in 1 2 3 4 5; do curl -s -o /dev/null http://localhost:8085/wordpress/wp-login.php; done
for c in 1 4 16 50 100 200; do
  rps=$(ab -q -n 500 -c $c -t 15 http://localhost:8085/wordpress/wp-login.php 2>&1 | grep "Requests per second" | head -1 | awk '{print $4}')
  echo "ZealPHP M5 c=$c → $rps RPS"
done

echo ""
echo "==> Memory snapshot"
docker stats --no-stream --format "{{.Name}}: {{.MemUsage}}" perf-zealphp perf-mysql

echo ""
echo "==> Side-by-side recap"
echo "Apache c=50:  2074 RPS (354 MiB)"
echo "ZealPHP M5 see above; expected to exceed Apache peak"
