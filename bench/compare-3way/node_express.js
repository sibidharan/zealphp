// Express.js — matches /raw/bench and /json endpoints from ZealPHP demo
const cluster = require('cluster');
const express = require('/tmp/node_modules/express');

const WORKERS = parseInt(process.env.NODE_WORKERS || '4', 10);
const PORT    = parseInt(process.env.NODE_PORT    || '18082', 10);

if (cluster.isPrimary) {
  console.log(`Express on :${PORT} with ${WORKERS} workers`);
  for (let i = 0; i < WORKERS; i++) cluster.fork();
  cluster.on('exit', () => cluster.fork());
} else {
  const app = express();
  app.disable('x-powered-by');
  app.disable('etag');

  app.get('/raw/:rest', (req, res) => {
    res.type('text/plain').send(`You requested: ${req.params.rest}`);
  });

  app.get('/json', (req, res) => {
    res.json({ __start_time: Date.now(), UNIQUE_REQUEST_ID: Math.random().toString(36).slice(2) });
  });

  app.listen(PORT);
}
