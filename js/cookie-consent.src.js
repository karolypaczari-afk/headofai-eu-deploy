/* =========================================
   Head of AI — cookie-consent.js
   Opt-out cookie banner. Granted-by-default
   (Consent Mode v2 default block already
   emitted inline in <head>).

   Shows banner only if user has not yet decided.
   "Elfogadom" confirms. "Csak szükséges" denies
   analytics + ads but keeps necessary cookies.
   ========================================= */

(function () {
  'use strict';

  if (document.querySelector('[data-cookie-banner]')) return;

  function readConsent() {
    if (window.HELM && window.HELM.getConsent) {
      var s = window.HELM.getConsent();
      if (s && !s._implicit) return s;
    }
    return null;
  }

  // If user already decided, do not show banner
  if (readConsent()) return;

  function build() {
    var wrap = document.createElement('div');
    wrap.setAttribute('data-cookie-banner', '');
    wrap.setAttribute('role', 'dialog');
    wrap.setAttribute('aria-live', 'polite');
    wrap.setAttribute('aria-label', 'Adatkezelési hozzájárulás');
    wrap.className = 'helm-cookie-banner';
    wrap.innerHTML =
      '<div class="helm-cookie-banner__inner">' +
        '<div class="helm-cookie-banner__copy">' +
          '<strong>Cookie-kat használunk.</strong> ' +
          'Méréshez (Google Analytics, Microsoft Clarity) és hirdetésekhez (Meta Pixel) ' +
          'sütiket helyezünk el. A részletek az ' +
          '<a href="/cookie-tajekoztato.html">cookie tájékoztatóban</a>.' +
        '</div>' +
        '<div class="helm-cookie-banner__actions">' +
          '<button type="button" data-consent="denied" class="helm-cookie-banner__btn helm-cookie-banner__btn--ghost">Csak szükséges</button>' +
          '<button type="button" data-consent="granted" class="helm-cookie-banner__btn helm-cookie-banner__btn--primary">Elfogadom</button>' +
        '</div>' +
      '</div>';
    return wrap;
  }

  function close(banner) {
    banner.classList.add('helm-cookie-banner--out');
    setTimeout(function () { banner.remove(); }, 400);
  }

  function mount() {
    var banner = build();
    document.body.appendChild(banner);
    requestAnimationFrame(function () { banner.classList.add('helm-cookie-banner--in'); });

    banner.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-consent]');
      if (!btn) return;
      var decision = btn.getAttribute('data-consent'); // 'granted' | 'denied'
      if (window.HELM && window.HELM.setConsent) {
        window.HELM.setConsent(decision);
      }
      // Analytics event (only fires if GA already loaded — that's fine)
      if (window.HELM && window.HELM.track) {
        window.HELM.track('helm_consent_decision', { decision: decision });
      }
      close(banner);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mount);
  } else {
    mount();
  }
})();
