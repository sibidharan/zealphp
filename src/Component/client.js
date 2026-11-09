/*
 * ZealPHP component push client — served at /_zeal/c/client.js by the
 * framework and loaded by App::componentPushRoot().
 *
 * Opens one connection per page (WebSocket or SSE), receives the ids of
 * stale components, and re-requests each one through the component
 * endpoint (`_refresh`) with its current snapshot and the visitor's own
 * cookies. The server never pushes HTML or data, only ids.
 *
 * Swaps with htmx when it's on the page (htmx 4: outerMorph, htmx 2:
 * outerHTML), otherwise replaces the element.
 */
(function () {
  'use strict';
  var script = document.currentScript;
  if (!script || !script.dataset.zcToken) return;

  var token = script.dataset.zcToken;
  var mode = script.dataset.zcPush === 'sse' ? 'sse' : 'ws';
  var ids = (script.dataset.zcIds || '').split(/\s+/).filter(Boolean);
  var base = '/_zeal/c';
  var ready = false;   // a connection has been accepted before
  var closed = false;
  var inflight = new Set(); // ids whose refresh is running
  var again = new Set();    // ids that went stale again meanwhile

  // hx-boost swaps the body without a page load, so a previous page's
  // connection may still be open: close it before opening ours.
  if (window.__zcPush) window.__zcPush.close();
  var current = { close: function () { closed = true; if (current.conn) current.conn.close(); } };
  window.__zcPush = current;

  function stillOnPage() {
    return ids.some(function (id) { return document.getElementById(id); });
  }

  // Navigating (hx-boost, history) to a page without these components ends the connection.
  ['htmx:afterSettle', 'htmx:after:settle', 'htmx:after:swap', 'popstate'].forEach(function (name) {
    window.addEventListener(name, function () {
      if (!closed && !stillOnPage()) current.close();
    });
  });

  function htmxVersion() {
    return window.htmx && htmx.version ? parseInt(String(htmx.version), 10) : 0;
  }

  function values(el) {
    if (el.tagName === 'FORM') return new FormData(el);
    var fd = new FormData();
    el.querySelectorAll('input[name], select[name], textarea[name]').forEach(function (input) {
      if ((input.type === 'checkbox' || input.type === 'radio') && !input.checked) return;
      if (input.tagName === 'SELECT' && input.multiple) {
        Array.prototype.forEach.call(input.selectedOptions, function (o) { fd.append(input.name, o.value); });
        return;
      }
      fd.append(input.name, input.value);
    });
    return fd;
  }

  function swap(el, html) {
    var v = htmxVersion();
    if (v >= 4) {
      htmx.swap({ target: el, text: html, swap: 'outerMorph' });
    } else if (v >= 2) {
      htmx.swap(el, html, { swapStyle: 'outerHTML' });
    } else {
      // Same trust as an htmx swap: this is the component's own markup from
      // the same-origin component endpoint, rendered from escaped templates.
      el.outerHTML = html;
    }
  }

  function busy(el) {
    // An htmx request from this component (an action) is running: its
    // response carries the new state, and refreshing now would race it.
    if (el.classList.contains('htmx-request') || el.querySelector('.htmx-request')) return true;
    // The visitor is typing in it; it refreshes itself on their next input.
    var a = document.activeElement;
    return el.contains(a) && /^(INPUT|TEXTAREA|SELECT)$/.test(a.tagName);
  }

  function refresh(id) {
    var el = document.getElementById(id);
    if (!el || !el.hasAttribute('data-zc')) return;
    if (inflight.has(id)) { again.add(id); return; }
    if (busy(el)) { setTimeout(function () { refresh(id); }, 300); return; }
    inflight.add(id);
    var v = htmxVersion();
    var headers = v ? { 'HX-Request': 'true' } : { 'Zeal-Request': '1' };
    if (v >= 4) headers['HX-Request-Type'] = 'partial';
    fetch(base + '/' + encodeURIComponent(el.getAttribute('data-zc')) + '/_refresh', {
      method: 'POST',
      body: new URLSearchParams(values(el)),
      headers: headers,
      credentials: 'same-origin'
    }).then(function (r) {
      if (r.headers.get('HX-Refresh') === 'true' || r.headers.get('Zeal-Reload') === '1') {
        location.reload();
        return null;
      }
      // A redirect (e.g. to a login page) is not this component's markup.
      return r.ok && !r.redirected ? r.text() : null;
    }).then(function (html) {
      var now = document.getElementById(id); // the element may have been swapped meanwhile
      if (html && now) swap(now, html);
    }).catch(function () { /* offline or navigating away */ }).then(function () {
      inflight.delete(id);
      if (again.delete(id)) refresh(id);
    });
  }

  function refreshAll() { ids.forEach(refresh); }

  function onMessage(data) {
    if (data === '[]') { onReady(); return; } // the socket was accepted
    if (!stillOnPage()) { current.close(); return; }
    try {
      JSON.parse(data).forEach(refresh);
    } catch (e) { /* ignore malformed messages */ }
  }

  function onReady() {
    // After a reconnect, refresh everything: invalidations may have been missed.
    if (ready) refreshAll();
    ready = true;
  }

  function onStale() {
    // The token belongs to another session or a retired key. Refreshing makes
    // stale components reload the page; push stays off until then.
    current.close();
    refreshAll();
  }

  if (mode === 'sse') {
    if (!window.EventSource) return;
    var es = new EventSource(base + '/stream?t=' + encodeURIComponent(token));
    current.conn = es;
    es.addEventListener('zc', function (e) { onMessage(e.data); });
    es.addEventListener('zc-ready', onReady);
    es.addEventListener('zc-stale', onStale);
    // zc-busy: the server sets a long retry; EventSource reconnects by itself.
    return;
  }

  var delay = 500;
  function connect() {
    if (closed || !stillOnPage()) return;
    var proto = location.protocol === 'https:' ? 'wss://' : 'ws://';
    var ws = new WebSocket(proto + location.host + base + '/ws?t=' + encodeURIComponent(token));
    current.conn = ws;
    ws.onmessage = function (e) {
      if (e.data === '[]') delay = 500;
      onMessage(e.data);
    };
    ws.onclose = function (e) {
      if (closed) return;
      if (e.code === 4409) { onStale(); return; }
      if (e.code === 4400) return; // token refused
      var wait = Math.min(delay, 60000) * (0.7 + Math.random() * 0.6);
      delay = Math.min(delay * 2, 60000);
      setTimeout(connect, wait);
    };
  }
  connect();
})();
