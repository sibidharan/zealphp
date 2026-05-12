const http = require('http');
const cluster = require('cluster');
const os = require('os');

const WORKERS = os.cpus().length; // match ZealPHP's worker count

function sleep(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
}

if (cluster.isPrimary) {
    for (let i = 0; i < WORKERS; i++) cluster.fork();
    cluster.on('exit', (w) => cluster.fork());
} else {
    http.createServer(async (req, res) => {
        const url = req.url.split('?')[0];

        // /quiz/:page — simple string response (mirrors ZealPHP /quiz/{page})
        if (url.startsWith('/quiz/')) {
            const page = url.slice(6);
            res.writeHead(200, { 'Content-Type': 'text/html' });
            res.end(`<h1>This is quiz: ${page}</h1>`);
            return;
        }

        // /json — return a JSON object (mirrors ZealPHP /json)
        if (url === '/json') {
            res.writeHead(200, { 'Content-Type': 'application/json' });
            res.end(JSON.stringify({ __start_time: Date.now(), UNIQUE_REQUEST_ID: Math.random().toString(36).slice(2) }));
            return;
        }

        // /co — 5 concurrent async sleeps (mirrors ZealPHP /co)
        if (url === '/co') {
            const results = await Promise.all([
                sleep(3000).then(() => 'Hello, Coroutine 1!'),
                sleep(3000).then(() => 'Hello, Coroutine! 2'),
                sleep(1000).then(() => 'Hello, Coroutine! 3'),
                sleep(2000).then(() => 'Hello, Coroutine! 4'),
                sleep(3000).then(() => 'Hello, Coroutine 5!'),
            ]);
            res.writeHead(200, { 'Content-Type': 'text/html' });
            res.end(`<pre>${JSON.stringify(results, null, 2)}</pre>`);
            return;
        }

        res.writeHead(404);
        res.end('Not Found');
    }).listen(3000);
}
