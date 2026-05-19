/* =========================================
   Head of AI — attribution.js
   90-day attribution cookie for paid + organic traffic.
   Captures gclid / fbclid / utm_* / msclkid / gbraid / wbraid
   from the URL, stores them, and exposes them so the form
   can inject hidden fields at submit time.
   ========================================= */

(function () {
  'use strict';

  var ATTR_KEY = 'helm_attrib';
  var ATTR_TTL_DAYS = 90;

  var CLICK_ID_PARAMS = ['gclid', 'gbraid', 'wbraid', 'fbclid', 'msclkid', 'ttclid', 'li_fat_id'];
  var UTM_PARAMS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id'];
  var ALL_PARAMS = CLICK_ID_PARAMS.concat(UTM_PARAMS);

  function readCookie(name) {
    var m = document.cookie.match(new RegExp('(?:^|;\\s*)' + name + '=([^;]+)'));
    return m ? decodeURIComponent(m[1]) : null;
  }

  function writeCookie(name, value, days) {
    var maxAge = days * 86400;
    document.cookie = name + '=' + encodeURIComponent(value) +
      '; Max-Age=' + maxAge + '; Path=/; SameSite=Lax' +
      (location.protocol === 'https:' ? '; Secure' : '');
  }

  function readStored() {
    try {
      var ls = localStorage.getItem(ATTR_KEY);
      if (ls) return JSON.parse(ls);
    } catch (e) { /* noop */ }
    var ck = readCookie(ATTR_KEY);
    if (ck) {
      try { return JSON.parse(ck); } catch (e) { /* noop */ }
    }
    return null;
  }

  function persist(state) {
    var payload = JSON.stringify(state);
    try { localStorage.setItem(ATTR_KEY, payload); } catch (e) { /* noop */ }
    writeCookie(ATTR_KEY, payload, ATTR_TTL_DAYS);
  }

  function captureFromURL() {
    var params;
    try { params = new URLSearchParams(window.location.search); }
    catch (e) { return null; }

    var captured = {};
    var hasAny = false;
    ALL_PARAMS.forEach(function (k) {
      var v = params.get(k);
      if (v) { captured[k] = v; hasAny = true; }
    });

    // referrer fallback for organic
    if (!hasAny && document.referrer) {
      try {
        var refHost = new URL(document.referrer).hostname;
        if (refHost && refHost !== window.location.hostname) {
          captured._referrer = refHost;
          captured._first_landing = window.location.pathname;
        }
      } catch (e) { /* noop */ }
    }

    return hasAny ? captured : (captured._referrer ? captured : null);
  }

  function merge(existing, fresh) {
    var out = existing || {};
    // First-touch fields preserved
    if (!out._first_touch_at) out._first_touch_at = new Date().toISOString();
    out._last_touch_at = new Date().toISOString();
    out._touch_count = (out._touch_count || 0) + 1;

    if (!fresh) return out;

    // Click IDs: last-click overwrites (paid attribution standard)
    CLICK_ID_PARAMS.forEach(function (k) {
      if (fresh[k]) out[k] = fresh[k];
    });
    // UTMs: fill only if missing OR if a click ID arrived (new ad session)
    var hasNewClick = CLICK_ID_PARAMS.some(function (k) { return !!fresh[k]; });
    UTM_PARAMS.forEach(function (k) {
      if (fresh[k] && (hasNewClick || !out[k])) out[k] = fresh[k];
    });
    if (fresh._referrer && !out._referrer) {
      out._referrer = fresh._referrer;
      out._first_landing = fresh._first_landing;
    }
    return out;
  }

  // ---- Boot ----
  var existing = readStored();
  var fresh = captureFromURL();
  var state = merge(existing, fresh);

  if (fresh || !existing) {
    persist(state);
  } else {
    // refresh touch counters even if no params
    persist(state);
  }

  // ---- Public API ----
  window.HELM = window.HELM || {};
  window.HELM.getAttribution = function () { return readStored() || {}; };

  // ---- Form injection ----
  window.HELM.injectAttributionIntoForm = function (formEl) {
    if (!formEl) return;
    var attr = window.HELM.getAttribution();
    Object.keys(attr).forEach(function (k) {
      var name = 'helm_attr_' + k;
      var existing = formEl.querySelector('input[name="' + name + '"]');
      if (existing) {
        existing.value = attr[k];
      } else {
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = attr[k];
        formEl.appendChild(input);
      }
    });
  };

  // Auto-inject for any form marked with [data-helm-form] on DOM ready
  function autoInject() {
    document.querySelectorAll('[data-helm-form]').forEach(function (f) {
      window.HELM.injectAttributionIntoForm(f);
    });
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', autoInject);
  } else {
    autoInject();
  }
})();
