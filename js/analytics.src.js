/* =========================================
   Head of AI — analytics.js
   Custom GA4 + Meta Pixel event dispatchers.
   Delegated listeners for CTA clicks, phone/email taps,
   form start/submit, scroll depth, article views.

   All events are namespaced with `helm_` so they are
   trivially filterable in GA4 + Clarity + Meta.
   ========================================= */

(function () {
  'use strict';

  var log = (window.HELM && window.HELM.tracking && window.HELM.tracking.log) || function () {};

  // ---------------------------------------------------------------
  // Event ID generator (used to dedup browser Pixel + server CAPI)
  // ---------------------------------------------------------------
  function uuid() {
    if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
      var r = Math.random() * 16 | 0, v = c === 'x' ? r : (r & 0x3 | 0x8);
      return v.toString(16);
    });
  }

  // ---------------------------------------------------------------
  // Core dispatcher — fires the event to every vendor present
  // ---------------------------------------------------------------
  function send(name, params, options) {
    params = params || {};
    options = options || {};
    var eventId = options.eventId || uuid();
    params.event_id = eventId;

    // GA4
    if (typeof window.gtag === 'function') {
      window.gtag('event', name, params);
    }

    // Meta Pixel (only standard / custom Pixel events)
    if (typeof window.fbq === 'function' && options.metaEvent) {
      var metaName = options.metaEvent;
      var metaParams = Object.assign({}, options.metaParams || {}, { eventID: eventId });
      // standard event vs. custom event
      var STANDARD = ['PageView', 'Lead', 'ViewContent', 'InitiateCheckout', 'CompleteRegistration', 'Contact', 'Subscribe'];
      if (STANDARD.indexOf(metaName) >= 0) {
        window.fbq('track', metaName, options.metaPayload || {}, { eventID: eventId });
      } else {
        window.fbq('trackCustom', metaName, options.metaPayload || {}, { eventID: eventId });
      }
    }

    // Clarity custom event tag
    if (typeof window.clarity === 'function') {
      try { window.clarity('event', name); } catch (e) { /* noop */ }
      if (params.cta_location) {
        try { window.clarity('set', 'cta_location', params.cta_location); } catch (e) { /* noop */ }
      }
    }

    log('event →', name, params, options);
    return eventId;
  }

  window.HELM = window.HELM || {};
  window.HELM.track = send;
  window.HELM.uuid = uuid;

  // ---------------------------------------------------------------
  // 1) CTA clicks — anything with [data-cta-location]
  // ---------------------------------------------------------------
  document.addEventListener('click', function (e) {
    var el = e.target.closest('[data-cta-location]');
    if (!el) return;
    var loc = el.getAttribute('data-cta-location');
    var label = (el.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 80);
    var href = el.getAttribute('href') || '';
    send('helm_cta_click', {
      cta_location: loc,
      cta_label: label,
      cta_href: href,
      page_path: location.pathname
    });
  }, true);

  // ---------------------------------------------------------------
  // 2) tel: + mailto: link taps
  // ---------------------------------------------------------------
  document.addEventListener('click', function (e) {
    var a = e.target.closest('a[href]');
    if (!a) return;
    var href = a.getAttribute('href') || '';
    if (href.indexOf('tel:') === 0) {
      send('helm_phone_click', { href: href, page_path: location.pathname });
    } else if (href.indexOf('mailto:') === 0) {
      send('helm_email_click', { href: href, page_path: location.pathname });
    }
  }, true);

  // ---------------------------------------------------------------
  // 3) Form start — first focus on a [data-helm-form] field
  // ---------------------------------------------------------------
  var formStarted = new WeakSet();
  document.addEventListener('focusin', function (e) {
    var form = e.target.closest('[data-helm-form]');
    if (!form || formStarted.has(form)) return;
    formStarted.add(form);
    send('helm_form_start', {
      form_id: form.getAttribute('id') || 'contact',
      page_path: location.pathname
    }, {
      metaEvent: 'InitiateCheckout',
      metaPayload: { content_name: 'helm-contact-form' }
    });
  }, true);

  // ---------------------------------------------------------------
  // 4) Scroll depth — 50% / 75% / 100%
  // ---------------------------------------------------------------
  var fired = { 50: false, 75: false, 100: false };
  function checkScroll() {
    var docH = document.documentElement.scrollHeight - window.innerHeight;
    if (docH <= 0) return;
    var pct = (window.scrollY / docH) * 100;
    [50, 75, 100].forEach(function (mark) {
      if (!fired[mark] && pct >= mark) {
        fired[mark] = true;
        send('helm_scroll_depth_' + mark, { page_path: location.pathname, depth: mark });
      }
    });
  }
  var scrollPending = false;
  window.addEventListener('scroll', function () {
    if (scrollPending) return;
    scrollPending = true;
    requestAnimationFrame(function () {
      checkScroll();
      scrollPending = false;
    });
  }, { passive: true });

  // ---------------------------------------------------------------
  // 5) Article view — fire after 30s + 50% scroll on tudastar pages
  // ---------------------------------------------------------------
  if (/\/tudastar\//.test(location.pathname) && document.body.dataset.articleSlug !== '') {
    var slug = document.body.dataset.articleSlug || location.pathname.split('/').filter(Boolean).pop().replace('.html', '');
    var articleFired = false;
    var startTs = Date.now();
    function maybeFireArticle() {
      if (articleFired) return;
      if (Date.now() - startTs < 30000) return;
      var docH = document.documentElement.scrollHeight - window.innerHeight;
      if (docH > 0 && window.scrollY / docH < 0.5) return;
      articleFired = true;
      send('helm_view_article', {
        article_slug: slug,
        page_path: location.pathname,
        time_on_page_sec: Math.round((Date.now() - startTs) / 1000)
      }, {
        metaEvent: 'ViewContent',
        metaPayload: { content_name: slug, content_category: 'tudastar' }
      });
    }
    window.addEventListener('scroll', maybeFireArticle, { passive: true });
    setTimeout(maybeFireArticle, 30000);
  }

  // ---------------------------------------------------------------
  // 6) Outbound link clicks (calendly, cal.com, linkedin, etc.)
  // ---------------------------------------------------------------
  document.addEventListener('click', function (e) {
    var a = e.target.closest('a[href]');
    if (!a) return;
    var href = a.getAttribute('href') || '';
    if (!/^https?:\/\//.test(href)) return;
    try {
      var url = new URL(href, location.href);
      if (url.hostname === location.hostname) return;
      var isCalendar = /cal\.com|calendly\.com/.test(url.hostname);
      send(isCalendar ? 'helm_calendar_book_intent' : 'helm_outbound_click', {
        href: href,
        host: url.hostname,
        page_path: location.pathname
      });
    } catch (err) { /* noop */ }
  }, true);

  // ---------------------------------------------------------------
  // 7) Thank-you page — fires generate_lead conversion if landed
  //    here organically without a fresh form submit (refresh-safe)
  // ---------------------------------------------------------------
  if (/koszonjuk\.html$/.test(location.pathname) || location.pathname.endsWith('/koszonjuk') || location.pathname.endsWith('/koszonjuk/')) {
    // Only fire once per session
    var KEY = 'helm_lead_fired';
    if (!sessionStorage.getItem(KEY)) {
      sessionStorage.setItem(KEY, '1');
      var leadId = window.HELM.uuid();
      send('helm_generate_lead', {
        page_path: location.pathname,
        lead_source: 'thank_you_page',
        currency: 'HUF',
        value: 10000
      }, {
        eventId: leadId,
        metaEvent: 'Lead',
        metaPayload: { currency: 'HUF', value: 10 }
      });
      // GA4 standard conversion event name + Google Ads conversion
      if (typeof window.gtag === 'function') {
        window.gtag('event', 'generate_lead', {
          currency: 'HUF',
          value: 10000,
          event_id: leadId
        });
        // Google Ads conversion fire (no-op if not configured)
        var cfg = (window.HELM_TRACKING_CONFIG && window.HELM_TRACKING_CONFIG.vendors) || {};
        if (cfg.gAdsId && cfg.gAdsLeadLabel && window.HELM.tracking.isRealId(cfg.gAdsId)) {
          window.gtag('event', 'conversion', {
            send_to: cfg.gAdsId + '/' + cfg.gAdsLeadLabel,
            value: 10000,
            currency: 'HUF',
            transaction_id: leadId
          });
        }
      }
    }
  }
})();
