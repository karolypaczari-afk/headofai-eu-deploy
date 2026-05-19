/* =========================================
   Head of AI — form.js
   Client-side validation + Formspree submit
   + analytics + Meta Advanced Matching +
   server CAPI dedup via shared event_id.
   ========================================= */

(function () {
  'use strict';

  var form = document.querySelector('[data-helm-form]');
  if (!form) return;

  var statusEl = form.querySelector('[data-form-status]');
  var submitBtn = form.querySelector('[data-form-submit]');
  var submitText = submitBtn ? submitBtn.querySelector('[data-submit-text]') : null;

  var setStatus = function (msg, type) {
    if (!statusEl) return;
    statusEl.textContent = msg;
    statusEl.className = 'mt-4 text-sm';
    if (type === 'error') statusEl.classList.add('text-red-400');
    else if (type === 'success') statusEl.classList.add('text-emerald-400');
    else statusEl.classList.add('text-slate-400');
  };

  var validateEmail = function (email) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(email);
  };

  var validatePhone = function (phone) {
    if (!phone) return true; // optional
    return /^(\+?36|06)[ \-]?\d{1,2}[ \-]?\d{3}[ \-]?\d{4}$/.test(phone.replace(/\s+/g, ' ').trim());
  };

  // Ensure attribution hidden fields are present at submit-time
  function reInjectAttribution() {
    if (window.HELM && window.HELM.injectAttributionIntoForm) {
      window.HELM.injectAttributionIntoForm(form);
    }
  }

  form.addEventListener('submit', async function (e) {
    e.preventDefault();

    // honeypot check
    var honeypot = form.querySelector('[name="_gotcha"]');
    if (honeypot && honeypot.value) {
      window.location.href = 'koszonjuk.html';
      return;
    }

    var formData = new FormData(form);
    var name = (formData.get('name') || '').trim();
    var company = (formData.get('company') || '').trim();
    var email = (formData.get('email') || '').trim();
    var phone = (formData.get('phone') || '').trim();
    var challenge = (formData.get('challenge') || '').trim();
    var gdpr = formData.get('gdpr');

    if (!name || name.length < 2) {
      setStatus('Add meg a neved, hogy tudjunk kihez szólni.', 'error');
      return;
    }
    if (!company || company.length < 2) {
      setStatus('Add meg a céged nevét.', 'error');
      return;
    }
    if (!validateEmail(email)) {
      setStatus('Adj meg egy érvényes e-mail-címet.', 'error');
      return;
    }
    if (phone && !validatePhone(phone)) {
      setStatus('A telefonszám formátuma magyar: +36 30 123 4567 vagy 06 30 123 4567.', 'error');
      return;
    }
    if (!challenge || challenge.length < 10) {
      setStatus('Írj pár szót arról, milyen folyamatot szeretnél AI-jal kiváltani – így fel tudunk készülni a beszélgetésre.', 'error');
      return;
    }
    if (!gdpr) {
      setStatus('Az adatkezelési tájékoztató elfogadása nélkül nem tudjuk fogadni a jelentkezést.', 'error');
      return;
    }

    // ---- Tracking prep ----
    var eventId = (window.HELM && window.HELM.uuid) ? window.HELM.uuid() : ('e-' + Date.now());

    // hash PII + push Meta Advanced Matching + Google Ads Enhanced Conversions
    if (window.HELM && window.HELM.hashAndStore) {
      try { await window.HELM.hashAndStore(email, phone); } catch (e) { /* noop */ }
    }
    if (window.HELM && window.HELM.pushMetaAdvancedMatching) {
      try { window.HELM.pushMetaAdvancedMatching(email, phone); } catch (e) { /* noop */ }
    }
    if (window.HELM && window.HELM.pushEnhancedConversionsData) {
      try { window.HELM.pushEnhancedConversionsData(email, phone); } catch (e) { /* noop */ }
    }

    // Re-inject attribution (covers late-fired URL captures)
    reInjectAttribution();

    // Add event_id to payload so server CAPI can dedup
    if (!form.querySelector('input[name="helm_event_id"]')) {
      var ei = document.createElement('input');
      ei.type = 'hidden';
      ei.name = 'helm_event_id';
      ei.value = eventId;
      form.appendChild(ei);
    } else {
      form.querySelector('input[name="helm_event_id"]').value = eventId;
    }

    // Fire form_submit + Lead via Meta Pixel (server CAPI uses same event_id)
    if (window.HELM && window.HELM.track) {
      window.HELM.track('helm_form_submit', {
        form_id: form.id || 'contact',
        page_path: location.pathname
      }, { eventId: eventId });

      window.HELM.track('helm_generate_lead', {
        form_id: form.id || 'contact',
        lead_source: 'contact_form',
        currency: 'HUF',
        value: 10000,
        page_path: location.pathname
      }, {
        eventId: eventId,
        metaEvent: 'Lead',
        metaPayload: { currency: 'HUF', value: 10, content_name: 'helm-contact-form' }
      });

      // GA4 standard conversion name + Google Ads conversion
      if (typeof window.gtag === 'function') {
        window.gtag('event', 'generate_lead', {
          currency: 'HUF', value: 10000, event_id: eventId
        });
        var cfg = (window.HELM_TRACKING_CONFIG && window.HELM_TRACKING_CONFIG.vendors) || {};
        var isReal = window.HELM.tracking && window.HELM.tracking.isRealId;
        if (cfg.gAdsId && cfg.gAdsLeadLabel && isReal && isReal(cfg.gAdsId)) {
          window.gtag('event', 'conversion', {
            send_to: cfg.gAdsId + '/' + cfg.gAdsLeadLabel,
            value: 10000, currency: 'HUF', transaction_id: eventId
          });
        }
      }
    }

    if (submitBtn) {
      submitBtn.disabled = true;
      if (submitText) submitText.textContent = 'Küldés…';
    }
    setStatus('Küldés folyamatban…', 'info');

    // Rebuild formData (may have new hidden inputs)
    formData = new FormData(form);

    try {
      var action = form.getAttribute('action');
      var res = await fetch(action, {
        method: 'POST',
        body: formData,
        headers: { 'Accept': 'application/json' }
      });
      if (res.ok) {
        // Fire-and-forget: server-side CAPI relay (if endpoint deployed)
        try {
          if (window.fetch) {
            fetch('/api/meta-capi.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({
                event_name: 'Lead',
                event_id: eventId,
                event_source_url: location.href,
                value: 10,
                currency: 'HUF',
                user_data: window.HELM && window.HELM.getStoredHashes ? window.HELM.getStoredHashes() : {}
              }),
              keepalive: true
            }).catch(function () {});
          }
        } catch (e) { /* noop */ }

        // Redirect to thank-you; attach event id so backend reconciliation can dedup
        window.location.href = 'koszonjuk.html?eid=' + encodeURIComponent(eventId);
      } else {
        var data = await res.json().catch(function () { return {}; });
        var msg = data && data.errors && data.errors[0] && data.errors[0].message
          ? data.errors[0].message
          : 'Hiba történt a küldés során. Próbáld újra, vagy írj közvetlenül: info@headofai.eu';
        setStatus(msg, 'error');
        if (submitBtn) submitBtn.disabled = false;
        if (submitText) submitText.textContent = 'Kérek egy ingyenes hívást';
      }
    } catch (err) {
      setStatus('Hálózati hiba. Próbáld újra pár másodperc múlva, vagy írj: info@headofai.eu', 'error');
      if (submitBtn) submitBtn.disabled = false;
      if (submitText) submitText.textContent = 'Kérek egy ingyenes hívást';
    }
  });
})();
