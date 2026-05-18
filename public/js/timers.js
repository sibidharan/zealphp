// /js/timers.js — SSE controller for the /timers page
(function () {
  'use strict';

  let eventSource = null;

  function logEl() {
    return document.getElementById('timer-sse-out');
  }

  function appendLine(text, cls) {
    const log = logEl();
    if (!log) return;
    const el = document.createElement('div');
    el.className = 'sse-event ' + (cls || '');
    el.textContent = new Date().toLocaleTimeString() + ' — ' + text;
    log.appendChild(el);
    log.scrollTop = log.scrollHeight;
  }

  function stop() {
    if (eventSource) {
      eventSource.close();
      eventSource = null;
    }
  }

  function start() {
    stop();
    const log = logEl();
    if (!log) return;
    log.textContent = '';
    eventSource = new EventSource('/timers/sse');
    eventSource.addEventListener('open', e => appendLine(e.data, 'open'));
    eventSource.addEventListener('tick', e => appendLine(e.data, 'tick'));
    eventSource.addEventListener('done', e => {
      appendLine(e.data, 'done');
      stop();
    });
    eventSource.onerror = () => {
      appendLine('connection closed', 'done');
      stop();
    };
  }

  function init() {
    const root = document.getElementById('timer-sse-out');
    if (!root || root.dataset.initialized) return;
    root.dataset.initialized = '1';
    document.querySelectorAll('[data-action="timers-sse-start"]')
      .forEach(btn => btn.addEventListener('click', start));
    document.querySelectorAll('[data-action="timers-sse-stop"]')
      .forEach(btn => btn.addEventListener('click', stop));
  }

  document.addEventListener('DOMContentLoaded', init);
  document.addEventListener('htmx:afterSettle', () => {
    stop();
    const root = document.getElementById('timer-sse-out');
    if (root) delete root.dataset.initialized;
    init();
  });
})();
