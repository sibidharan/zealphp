/*
 * ZealPHP components without htmx — served at /_zeal/c/zeal.js for
 * ZealJsBinder. Speaks the plain protocol: requests send `Zeal-Request: 1`;
 * responses are the re-rendered component (200, or 422 with validation
 * errors) plus `Zeal-Events`, `Zeal-Redirect` and `Zeal-Reload` headers.
 *
 * The markup is a working form without this script; the script only makes
 * it update in place.
 */
(function () {
  'use strict';
  if (window.__zealJs) return;
  window.__zealJs = true;

  var controllers = new Map(); // component id → AbortController of its request in flight
  var timers = new WeakMap();  // live input → debounce timer

  function rootOf(el) {
    return el && el.closest ? el.closest('[data-zc]') : null;
  }

  // A component's fields: its form's own fields, or (for a non-form root)
  // the named controls inside it, the way a browser would submit them.
  function fields(root, submitter) {
    if (root.tagName === 'FORM') {
      return submitter ? new FormData(root, submitter) : new FormData(root);
    }
    var fd = new FormData();
    root.querySelectorAll('input[name], select[name], textarea[name]').forEach(function (input) {
      if (input.disabled) return;
      if ((input.type === 'checkbox' || input.type === 'radio') && !input.checked) return;
      if (input.type === 'file') {
        Array.prototype.forEach.call(input.files || [], function (f) { fd.append(input.name, f); });
        return;
      }
      if (input.tagName === 'SELECT' && input.multiple) {
        Array.prototype.forEach.call(input.selectedOptions, function (o) { fd.append(input.name, o.value); });
        return;
      }
      fd.append(input.name, input.value);
    });
    if (submitter && submitter.name) fd.append(submitter.name, submitter.value);
    return fd;
  }

  // Replace the component, keeping the field being typed in (value, focus,
  // cursor), as htmx's hx-preserve / morphing do.
  function swap(root, html) {
    // Same trust as an htmx swap: the component's own markup from its
    // same-origin endpoint, rendered from escaped templates. A <template>
    // parses it inertly (scripts in it don't run).
    var tpl = document.createElement('template');
    tpl.innerHTML = html.trim();
    var next = tpl.content.firstElementChild;
    if (!next) return null;
    var a = document.activeElement;
    var keep = null;
    if (a && root.contains(a) && a.id && /^(INPUT|TEXTAREA|SELECT)$/.test(a.tagName) && a.type !== 'checkbox' && a.type !== 'radio') {
      keep = { id: a.id, value: a.value, start: a.selectionStart, end: a.selectionEnd };
    }
    root.replaceWith(next);
    if (keep) {
      var field = document.getElementById(keep.id);
      if (field) {
        if (field.value !== keep.value) field.value = keep.value;
        field.focus();
        try { field.setSelectionRange(keep.start, keep.end); } catch (e) { /* not a text field */ }
      }
    }
    return next;
  }

  function emit(el, eventsHeader) {
    if (!eventsHeader) return;
    var events;
    try { events = JSON.parse(eventsHeader); } catch (e) { return; }
    Object.keys(events || {}).forEach(function (name) {
      el.dispatchEvent(new CustomEvent(name, { detail: events[name], bubbles: true }));
    });
  }

  function send(root, url, body, button) {
    var id = root.id;
    var previous = controllers.get(id);
    if (previous) previous.abort(); // the newest request carries every field anyway
    var ctrl = new AbortController();
    controllers.set(id, ctrl);
    if (button) button.disabled = true;
    root.setAttribute('aria-busy', 'true');
    fetch(url, {
      method: 'POST',
      body: body,
      headers: { 'Zeal-Request': '1' },
      credentials: 'same-origin',
      signal: ctrl.signal
    }).then(function (r) {
      if (r.headers.get('Zeal-Reload') === '1') { location.reload(); return; }
      var to = r.headers.get('Zeal-Redirect');
      if (to) { location.assign(to); return; }
      if (r.redirected) { location.assign(r.url); return; } // e.g. sent to a login page
      if (r.status !== 200 && r.status !== 422) {
        if (window.console) console.warn('zeal: component request refused (' + r.status + ')');
        return;
      }
      return r.text().then(function (html) {
        var current = document.getElementById(id) || root;
        var next = swap(current, html);
        if (next) emit(next, r.headers.get('Zeal-Events'));
      });
    }).catch(function (e) {
      if (e && e.name === 'AbortError') return;
      if (window.console) console.warn('zeal: component request failed', e);
    }).then(function () {
      if (controllers.get(id) === ctrl) controllers.delete(id);
      if (button) button.disabled = false;
      var now = document.getElementById(id);
      if (now) now.removeAttribute('aria-busy');
    });
  }

  // Form roots: actions (buttons with formaction) and Enter both submit.
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-zc')) return;
    e.preventDefault();
    var button = e.submitter && e.submitter.hasAttribute('data-zc-action') ? e.submitter : null;
    var url = button ? button.getAttribute('formaction') : form.getAttribute('action');
    send(form, url, fields(form, e.submitter || null), button);
  });

  // Actions in a non-form root: no submit event fires, so take the click.
  document.addEventListener('click', function (e) {
    var button = e.target && e.target.closest ? e.target.closest('[data-zc-action]') : null;
    if (!button || button.form) return; // a form root handles it on submit
    var root = rootOf(button);
    if (!root) return;
    e.preventDefault();
    send(root, button.getAttribute('formaction'), fields(root, button), button);
  });

  function update(input) {
    var root = rootOf(input);
    if (!root) return;
    var body = fields(root, null);
    body.append('_zc_trigger', input.name.replace(/\[\]$/, ''));
    send(root, input.getAttribute('data-zc-update'), body, null);
  }

  document.addEventListener('input', function (e) {
    var input = e.target;
    if (!input || !input.hasAttribute || !input.hasAttribute('data-zc-live')) return;
    clearTimeout(timers.get(input));
    var delay = parseInt(input.getAttribute('data-zc-live'), 10) || 0;
    timers.set(input, setTimeout(function () { update(input); }, delay));
  });

  document.addEventListener('change', function (e) {
    var input = e.target;
    if (input && input.hasAttribute && input.hasAttribute('data-zc-blur')) update(input);
  });
})();
