/* =========================================
   Head of AI — meta-enhance.js
   SHA-256 hashing of email + phone for
   Meta Advanced Matching + Google Ads
   Enhanced Conversions.

   Hashes are stored only in localStorage on
   the user's own device. Plaintext PII never
   leaves the page before hashing.
   ========================================= */

(function () {
  'use strict';

  var KEY_EMAIL = 'helm_em_hash';
  var KEY_PHONE = 'helm_ph_hash';

  function normEmail(v) {
    if (!v) return '';
    return String(v).trim().toLowerCase();
  }
  function normPhone(v) {
    if (!v) return '';
    var digits = String(v).replace(/\D/g, '');
    // Hungarian: 06… → 36…
    if (digits.indexOf('06') === 0) digits = '36' + digits.slice(2);
    return digits;
  }

  function sha256(text) {
    if (!text) return Promise.resolve('');
    if (!(window.crypto && crypto.subtle && crypto.subtle.digest)) {
      return Promise.resolve('');
    }
    var buf = new TextEncoder().encode(text);
    return crypto.subtle.digest('SHA-256', buf).then(function (hash) {
      var bytes = new Uint8Array(hash);
      var hex = '';
      for (var i = 0; i < bytes.length; i++) {
        hex += ('00' + bytes[i].toString(16)).slice(-2);
      }
      return hex;
    });
  }

  window.HELM = window.HELM || {};

  window.HELM.hashAndStore = function (email, phone) {
    var jobs = [];
    if (email) {
      jobs.push(sha256(normEmail(email)).then(function (h) {
        if (h) {
          try { localStorage.setItem(KEY_EMAIL, h); } catch (e) {}
        }
        return h;
      }));
    } else {
      jobs.push(Promise.resolve(null));
    }
    if (phone) {
      jobs.push(sha256(normPhone(phone)).then(function (h) {
        if (h) {
          try { localStorage.setItem(KEY_PHONE, h); } catch (e) {}
        }
        return h;
      }));
    } else {
      jobs.push(Promise.resolve(null));
    }
    return Promise.all(jobs).then(function (res) {
      return { email_hash: res[0], phone_hash: res[1] };
    });
  };

  window.HELM.getStoredHashes = function () {
    var em = null, ph = null;
    try { em = localStorage.getItem(KEY_EMAIL); } catch (e) {}
    try { ph = localStorage.getItem(KEY_PHONE); } catch (e) {}
    return { email_hash: em, phone_hash: ph };
  };

  // Push to Meta Pixel Advanced Matching
  window.HELM.pushMetaAdvancedMatching = function (email, phone) {
    if (typeof window.fbq !== 'function') return;
    var data = {};
    if (email) data.em = normEmail(email);
    if (phone) data.ph = normPhone(phone);
    if (Object.keys(data).length === 0) return;
    var cfg = (window.HELM_TRACKING_CONFIG && window.HELM_TRACKING_CONFIG.vendors) || {};
    if (!cfg.metaPixelId) return;
    try { window.fbq('init', cfg.metaPixelId, data); } catch (e) {}
  };

  // Push to Google Ads Enhanced Conversions
  window.HELM.pushEnhancedConversionsData = function (email, phone) {
    if (typeof window.gtag !== 'function') return;
    var cfg = (window.HELM_TRACKING_CONFIG && window.HELM_TRACKING_CONFIG.vendors) || {};
    if (!cfg.gAdsId) return;
    var payload = {};
    if (email) payload.email = normEmail(email);
    if (phone) payload.phone_number = normPhone(phone);
    if (Object.keys(payload).length === 0) return;
    window.gtag('set', 'user_data', payload);
  };
})();
