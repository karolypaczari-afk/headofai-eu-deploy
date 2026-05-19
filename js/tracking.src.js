/* =========================================
   Head of AI — tracking.js
   Vendor config + Consent Mode v2 + lazy loaders
   for GA4, Microsoft Clarity, Meta Pixel.

   Production-ready. To go live, replace the
   placeholder IDs below with real ones — that's it.
   ========================================= */

(function () {
  'use strict';

  // ---------------------------------------------------------------
  // 1) VENDOR CONFIG — REPLACE THESE BEFORE GOING LIVE
  // ---------------------------------------------------------------
  window.HELM_TRACKING_CONFIG = {
    vendors: {
      // GTM (leave empty for direct gtag.js setup; populate only when a published GTM container exists)
      gtmId: '',                               // e.g. 'GTM-XXXXXXX'

      // GA4 Measurement ID (https://analytics.google.com)
      gaMeasurementId: 'G-XXXXXXXXXX',         // REPLACE

      // Google Ads
      gAdsId: '',                              // e.g. 'AW-1234567890' — fill when ads launch
      gAdsLeadLabel: '',                       // conversion label for "Lead"

      // Microsoft Clarity (https://clarity.microsoft.com)
      clarityId: 'XXXXXXXXXX',                 // REPLACE

      // Meta Pixel (Business Manager → Events Manager)
      metaPixelId: 'XXXXXXXXXXXXXXXXX',        // REPLACE

      // Optional: TikTok / LinkedIn — not wired yet
      tiktokId: '',
      linkedinId: ''
    },

    // Consent — opt-out model: granted by default, banner offers reject
    requireConsent: true,

    // Domain on which the attribution cookie should live (leave '' for current host)
    cookieDomain: '',

    // Debug flag — set ?helm_debug=1 in URL to force-enable
    debug: false
  };

  // ---------------------------------------------------------------
  // 2) DEBUG MODE
  // ---------------------------------------------------------------
  try {
    var qp = new URLSearchParams(window.location.search);
    if (qp.get('helm_debug') === '1') window.HELM_TRACKING_CONFIG.debug = true;
  } catch (e) { /* noop */ }
  var DEBUG = window.HELM_TRACKING_CONFIG.debug;
  var log = function () { if (DEBUG && window.console) console.log.apply(console, ['[HELM]'].concat([].slice.call(arguments))); };

  // ---------------------------------------------------------------
  // 3) CONSENT STATE
  // ---------------------------------------------------------------
  // Default: granted by default (Consent Mode v2 default block already
  // emitted inline in <head>). When user rejects, we update to denied
  // and persist. Cookie/localStorage key: helm_consent.
  // ---------------------------------------------------------------
  var CONSENT_KEY = 'helm_consent';
  var CONSENT_TTL_DAYS = 180;

  function readConsent() {
    try {
      var v = localStorage.getItem(CONSENT_KEY);
      if (v) return JSON.parse(v);
    } catch (e) { /* noop */ }
    // fallback cookie
    var m = document.cookie.match(/(?:^|;\s*)helm_consent=([^;]+)/);
    if (m) {
      try { return JSON.parse(decodeURIComponent(m[1])); } catch (e) { /* noop */ }
    }
    return null;
  }

  function persistConsent(state) {
    var payload = JSON.stringify(state);
    try { localStorage.setItem(CONSENT_KEY, payload); } catch (e) { /* noop */ }
    var maxAge = CONSENT_TTL_DAYS * 86400;
    document.cookie = 'helm_consent=' + encodeURIComponent(payload) +
      '; Max-Age=' + maxAge + '; Path=/; SameSite=Lax' +
      (location.protocol === 'https:' ? '; Secure' : '');
  }

  window.HELM = window.HELM || {};

  window.HELM.getConsent = function () {
    var s = readConsent();
    if (s) return s;
    // implicit-granted default — matches Consent Mode v2 default block
    return {
      ad_storage: 'granted',
      analytics_storage: 'granted',
      ad_user_data: 'granted',
      ad_personalization: 'granted',
      functionality_storage: 'granted',
      security_storage: 'granted',
      _implicit: true
    };
  };

  window.HELM.setConsent = function (decision) {
    // decision: 'granted' | 'denied'
    var state = {
      ad_storage: decision,
      analytics_storage: decision,
      ad_user_data: decision,
      ad_personalization: decision,
      functionality_storage: 'granted',     // always granted (necessary)
      security_storage: 'granted'           // always granted (necessary)
    };
    persistConsent(state);
    if (typeof window.gtag === 'function') {
      window.gtag('consent', 'update', state);
    }
    log('consent updated', state);
    // Re-evaluate loaders
    loadVendorsIfAllowed();
    return state;
  };

  // ---------------------------------------------------------------
  // 4) GTAG STUB — works whether or not a vendor has loaded yet.
  // The inline <head> block already initialized window.dataLayer and
  // window.gtag with the Consent Mode v2 default. We just re-bind here.
  // ---------------------------------------------------------------
  window.dataLayer = window.dataLayer || [];
  if (typeof window.gtag !== 'function') {
    window.gtag = function () { window.dataLayer.push(arguments); };
  }

  // ---------------------------------------------------------------
  // 5) VENDOR LOADERS — lazy, gated on consent + valid placeholder ID
  // ---------------------------------------------------------------
  var loaded = { ga: false, clarity: false, metaPixel: false, gAds: false };
  var PLACEHOLDER_PATTERNS = [/^G-X+$/i, /^AW-X+$/i, /^X+$/i, /REPLACE/i];

  function isRealId(v) {
    if (!v) return false;
    for (var i = 0; i < PLACEHOLDER_PATTERNS.length; i++) {
      if (PLACEHOLDER_PATTERNS[i].test(v)) return false;
    }
    return true;
  }

  function injectScript(src, opts) {
    return new Promise(function (resolve, reject) {
      var s = document.createElement('script');
      s.async = true;
      s.src = src;
      if (opts && opts.attrs) {
        Object.keys(opts.attrs).forEach(function (k) { s.setAttribute(k, opts.attrs[k]); });
      }
      s.onload = function () { resolve(); };
      s.onerror = function () { reject(new Error('Failed to load ' + src)); };
      document.head.appendChild(s);
    });
  }

  function loadGA4() {
    if (loaded.ga) return;
    var id = window.HELM_TRACKING_CONFIG.vendors.gaMeasurementId;
    if (!isRealId(id)) { log('GA4 not loaded — placeholder ID'); return; }
    loaded.ga = true;
    injectScript('https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(id))
      .then(function () {
        window.gtag('js', new Date());
        window.gtag('config', id, {
          anonymize_ip: true,
          allow_google_signals: true,
          send_page_view: true,
          transport_type: 'beacon'
        });
        log('GA4 loaded', id);
        window.dispatchEvent(new CustomEvent('helm:ga4-ready'));
      })
      .catch(function (err) { log('GA4 load fail', err); });
  }

  function loadGoogleAds() {
    if (loaded.gAds) return;
    var id = window.HELM_TRACKING_CONFIG.vendors.gAdsId;
    if (!isRealId(id)) return;
    loaded.gAds = true;
    // Google Ads uses gtag.js too — already loaded by GA4. Just register config.
    if (typeof window.gtag === 'function') {
      window.gtag('config', id, { allow_enhanced_conversions: true });
      log('Google Ads loaded', id);
    }
  }

  function loadClarity() {
    if (loaded.clarity) return;
    var id = window.HELM_TRACKING_CONFIG.vendors.clarityId;
    if (!isRealId(id)) { log('Clarity not loaded — placeholder ID'); return; }
    loaded.clarity = true;
    // Microsoft Clarity boot snippet (official)
    (function (c, l, a, r, i, t, y) {
      c[a] = c[a] || function () { (c[a].q = c[a].q || []).push(arguments); };
      t = l.createElement(r); t.async = 1;
      t.src = 'https://www.clarity.ms/tag/' + i;
      y = l.getElementsByTagName(r)[0]; y.parentNode.insertBefore(t, y);
    })(window, document, 'clarity', 'script', id);
    log('Clarity loaded', id);
    window.dispatchEvent(new CustomEvent('helm:clarity-ready'));
  }

  function loadMetaPixel() {
    if (loaded.metaPixel) return;
    var id = window.HELM_TRACKING_CONFIG.vendors.metaPixelId;
    if (!isRealId(id)) { log('Meta Pixel not loaded — placeholder ID'); return; }
    loaded.metaPixel = true;
    // Meta Pixel boot snippet (official)
    !function (f, b, e, v, n, t, s) {
      if (f.fbq) return; n = f.fbq = function () {
        n.callMethod ? n.callMethod.apply(n, arguments) : n.queue.push(arguments);
      };
      if (!f._fbq) f._fbq = n; n.push = n; n.loaded = !0; n.version = '2.0';
      n.queue = []; t = b.createElement(e); t.async = !0;
      t.src = v; s = b.getElementsByTagName(e)[0];
      s.parentNode.insertBefore(t, s);
    }(window, document, 'script', 'https://connect.facebook.net/en_US/fbevents.js');
    window.fbq('init', id);
    window.fbq('track', 'PageView');
    log('Meta Pixel loaded', id);
    window.dispatchEvent(new CustomEvent('helm:meta-pixel-ready'));
  }

  // ---------------------------------------------------------------
  // 6) DECIDE WHEN TO LOAD
  // ---------------------------------------------------------------
  function loadVendorsIfAllowed() {
    var consent = window.HELM.getConsent();
    var analyticsOK = consent.analytics_storage === 'granted';
    var adsOK = consent.ad_storage === 'granted';

    if (analyticsOK) {
      loadGA4();
      loadClarity();
    }
    if (adsOK) {
      loadMetaPixel();
      loadGoogleAds();
    }
  }

  // Defer to idle so we don't block first paint
  function bootstrap() {
    if ('requestIdleCallback' in window) {
      requestIdleCallback(loadVendorsIfAllowed, { timeout: 2000 });
    } else {
      setTimeout(loadVendorsIfAllowed, 1500);
    }
  }

  if (document.readyState === 'complete' || document.readyState === 'interactive') {
    bootstrap();
  } else {
    document.addEventListener('DOMContentLoaded', bootstrap);
  }

  // Re-expose for analytics module
  window.HELM.tracking = {
    loadVendorsIfAllowed: loadVendorsIfAllowed,
    isRealId: isRealId,
    log: log
  };
})();
