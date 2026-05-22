// Page-scoped script for /migration — the live Apache/nginx → app.php
// config converter. Extracted verbatim from the page's inline <script>
// (separation-of-concerns rule). Behaviour unchanged.
(function() {
  const PRESETS = {
    wordpress: `# BEGIN WordPress
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteBase /
RewriteRule ^index\\.php$ - [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.php [L]
</IfModule>
# END WordPress`,
    'nginx-cms': `server {
    listen 80;
    server_name example.com;
    root /var/www/html;

    location / {
        try_files $uri $uri/ /index.php?$args;
    }
    location ~ \\.php$ {
        fastcgi_pass unix:/run/php/php-fpm.sock;
        include fastcgi_params;
    }
    location ~* \\.(css|js|png|jpg|gif|ico)$ {
        expires 30d;
    }
}`,
    redirects: `RewriteEngine On
RewriteRule ^old-page$ /new-page [R=301,L]
RewriteRule ^blog/(.*)$ /articles/$1 [R=302,L]
RewriteRule ^docs$ https://docs.example.com [R=301,L]`
  };

  document.getElementById('convert-preset').addEventListener('change', function() {
    if (this.value && PRESETS[this.value]) {
      document.getElementById('convert-input').value = PRESETS[this.value];
    }
  });

  window.runConvert = function() {
    const input = document.getElementById('convert-input').value.trim();
    const output = document.getElementById('convert-output');
    const status = document.getElementById('convert-status');
    const btn = document.getElementById('convert-btn');

    if (!input) { status.textContent = 'Paste a config first'; return; }

    btn.disabled = true;
    btn.textContent = 'Converting...';
    status.textContent = 'Streaming from gpt-5.4-mini...';
    output.textContent = '';

    fetch('/api/convert', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({config: input})
    }).then(response => {
      const reader = response.body.getReader();
      const decoder = new TextDecoder();
      let buffer = '';

      function read() {
        reader.read().then(({done, value}) => {
          if (done) {
            btn.disabled = false;
            btn.textContent = 'Convert →';
            status.textContent = 'Done';
            return;
          }
          buffer += decoder.decode(value, {stream: true});
          const lines = buffer.split('\n');
          buffer = lines.pop();
          for (const line of lines) {
            if (line.startsWith('data: ')) {
              const text = line.slice(6);
              if (text === '[DONE]') continue;
              output.textContent += text + '\n';
            }
          }
          output.scrollTop = output.scrollHeight;
          read();
        });
      }
      read();
    }).catch(err => {
      output.textContent = '// Error: ' + err.message;
      btn.disabled = false;
      btn.textContent = 'Convert →';
      status.textContent = 'Failed';
    });
  };

  window.copyOutput = function() {
    const text = document.getElementById('convert-output').textContent;
    navigator.clipboard.writeText(text).then(() => {
      const btn = event.target;
      btn.textContent = 'Copied!';
      setTimeout(() => btn.textContent = 'Copy', 1500);
    });
  };
})();
