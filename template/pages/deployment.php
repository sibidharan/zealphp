<?php
use ZealPHP\App;
?>
<section class="section">
<div class="container">
<h1 class="section-title">Deploy</h1>
<p class="section-desc">ZealPHP is a long-lived OpenSwoole server process. One process per port, N workers per process. Run behind a reverse proxy (nginx or Caddy) for TLS, static assets, and request logging.</p>

<h2 style="margin:1.5rem 0 .5rem">systemd</h2>
<p>A reference unit is shipped at <code>deploy/zealphp.service</code>. Copy it to <code>/etc/systemd/system/zealphp.service</code>, adjust <code>User</code>, <code>WorkingDirectory</code>, and <code>ExecStart</code>, then:</p>

<?php
App::render('/components/_code', [
    'label' => 'Enable and start the service',
    'lang'  => 'bash',
    'code'  => <<<'BASH'
sudo systemctl daemon-reload
sudo systemctl enable zealphp
sudo systemctl start zealphp
sudo systemctl status zealphp

# Logs:
sudo journalctl -u zealphp -f
BASH
]);
?>

<p>The unit is <code>Type=simple</code> — do not pass <code>-d</code> in <code>ExecStart</code>, systemd manages the lifecycle.</p>

<h2 style="margin:2rem 0 .5rem">Environment variables</h2>
<table class="ztable" style="margin-bottom:1.5rem">
  <tr><th>Variable</th><th>Default</th><th>Purpose</th></tr>
  <tr><td><code>ZEALPHP_HOST</code></td><td><code>0.0.0.0</code></td><td>Bind address</td></tr>
  <tr><td><code>ZEALPHP_PORT</code></td><td><code>8080</code></td><td>Listen port</td></tr>
  <tr><td><code>ZEALPHP_WORKERS</code></td><td>CPU count</td><td>HTTP worker processes</td></tr>
  <tr><td><code>ZEALPHP_TASK_WORKERS</code></td><td><code>0</code></td><td>Background task workers</td></tr>
  <tr><td><code>ZEALPHP_SESSION_SECURE</code></td><td>auto-detect</td><td>Force <code>Secure</code> cookie flag (<code>1</code>/<code>0</code>). Auto-detects HTTPS via <code>HTTPS</code>, <code>HTTP_X_FORWARDED_PROTO</code>, or <code>SERVER_PORT=443</code> headers.</td></tr>
  <tr><td><code>ZEALPHP_COMPRESSION_MIDDLEWARE</code></td><td><code>0</code></td><td>Enable the reference PHP compression middleware (only if OpenSwoole's native <code>http_compression</code> is disabled).</td></tr>
  <tr><td><code>ZEALPHP_HTTP_COMPRESSION</code></td><td><code>1</code></td><td>Enable OpenSwoole native HTTP compression.</td></tr>
  <tr><td><code>ZEALPHP_SITE_URL</code></td><td>request host</td><td>Override the URL used in demo links rendered by the site.</td></tr>
</table>

<h2 style="margin:2rem 0 .5rem">nginx reverse proxy</h2>

<?php
App::render('/components/_code', [
    'label' => '/etc/nginx/sites-available/zealphp',
    'lang'  => 'nginx',
    'code'  => <<<'NGINX'
server {
    listen 443 ssl http2;
    server_name example.com;

    ssl_certificate     /etc/letsencrypt/live/example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/example.com/privkey.pem;

    location / {
        proxy_pass         http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header   Host              $host;
        proxy_set_header   X-Real-IP         $remote_addr;
        proxy_set_header   X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header   X-Forwarded-Proto $scheme;

        # WebSocket upgrades on /ws/*
        proxy_set_header   Upgrade    $http_upgrade;
        proxy_set_header   Connection "upgrade";

        # SSE: disable response buffering and bump timeout
        proxy_buffering    off;
        proxy_read_timeout 86400;
    }
}
NGINX
]);
?>

<h2 style="margin:2rem 0 .5rem">Caddy reverse proxy</h2>

<?php
App::render('/components/_code', [
    'label' => 'Caddyfile',
    'lang'  => 'caddyfile',
    'code'  => <<<'CADDY'
example.com {
    reverse_proxy 127.0.0.1:8080 {
        flush_interval -1   # streaming SSE / WebSocket
    }
}
CADDY
]);
?>

<h2 style="margin:2rem 0 .5rem">Docker</h2>
<p>The repo ships a <code>Dockerfile</code> and <code>docker-compose.yml</code>. For production:</p>

<?php
App::render('/components/_code', [
    'label' => 'docker-compose.yml (production sketch)',
    'lang'  => 'yaml',
    'code'  => <<<'YAML'
services:
  app:
    image: zealphp:0.2.1
    restart: unless-stopped
    ports:
      - "127.0.0.1:8080:8080"
    environment:
      ZEALPHP_HOST: 0.0.0.0
      ZEALPHP_PORT: 8080
      ZEALPHP_WORKERS: 8
      ZEALPHP_SESSION_SECURE: "1"
    volumes:
      - sessions:/var/lib/php/sessions
    healthcheck:
      test: ["CMD", "curl", "-fsS", "http://127.0.0.1:8080/"]
      interval: 30s
      timeout: 5s
      retries: 3
volumes:
  sessions:
YAML
]);
?>

<h2 style="margin:2rem 0 .5rem">Production checklist</h2>
<ul>
  <li>Run as a non-root user; bind to <code>8080</code>, not <code>80</code>.</li>
  <li>Pin OpenSwoole + uopz extension versions in your Dockerfile.</li>
  <li>Set <code>ZEALPHP_WORKERS</code> ≈ CPU cores.</li>
  <li>Ensure the session save path (<code>/var/lib/php/sessions</code>) is writable by the service user and mode <code>0700</code>.</li>
  <li>Disable debug logging in production (<code>ZEALPHP_DEBUG=0</code> or unset).</li>
  <li>Rotate logs in <code>/tmp/zealphp/</code> with <code>logrotate</code>.</li>
  <li>Behind a TLS-terminating proxy, leave <code>ZEALPHP_SESSION_SECURE</code> unset — the framework auto-detects HTTPS from <code>X-Forwarded-Proto</code>.</li>
  <li>Use <code>php app.php restart</code> for graceful, zero-downtime worker recycling.</li>
</ul>

<h2 style="margin:2rem 0 .5rem">More</h2>
<p>Detailed write-up: <a href="https://github.com/sibidharan/zealphp/blob/master/docs/deployment.md" target="_blank">docs/deployment.md</a> on GitHub.</p>

</div>
</section>
