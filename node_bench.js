const http = require('http');
const cluster = require('cluster');
const os = require('os');

const WORKERS = Number.parseInt(process.env.NODE_WORKERS || process.env.WORKERS || os.cpus().length, 10);
const PORT = Number.parseInt(process.env.NODE_PORT || process.env.PORT || 3000, 10);

function sleep(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
}

function respond(res, statusCode, contentType, body) {
    res.writeHead(statusCode, {
        'Content-Type': contentType,
        'Content-Length': Buffer.byteLength(body),
        'Connection': 'keep-alive',
    });
    res.end(body);
}

if (cluster.isPrimary) {
    console.log(`Node benchmark server starting on :${PORT} with ${WORKERS} workers`);
    for (let i = 0; i < WORKERS; i++) cluster.fork();
    cluster.on('exit', (w) => cluster.fork());
} else {
    const server = http.createServer(async (req, res) => {
        const url = req.url.split('?')[0];

        // /raw/:rest - simple text response matching ZealPHP /raw/*
        if (url.startsWith('/raw/')) {
            const rest = url.slice(5);
            respond(res, 200, 'text/plain', `You requested: ${rest}`);
            return;
        }

        // /quiz/:page — simple string response (mirrors ZealPHP /quiz/{page})
        if (url.startsWith('/quiz/')) {
            const page = url.slice(6);
            respond(res, 200, 'text/html', `<h1>This is quiz: ${page}</h1>`);
            return;
        }

        // /json — return a JSON object (mirrors ZealPHP /json)
        if (url === '/json') {
            respond(res, 200, 'application/json', JSON.stringify({
                __start_time: Date.now(),
                UNIQUE_REQUEST_ID: Math.random().toString(36).slice(2),
            }));
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
            respond(res, 200, 'text/html', `<pre>${JSON.stringify(results, null, 2)}</pre>`);
            return;
        }

        respond(res, 404, 'text/plain', 'Not Found');
    });

    server.keepAliveTimeout = 5000;
    server.headersTimeout = 6000;
    server.listen(PORT);
}
