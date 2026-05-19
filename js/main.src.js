/* =========================================
   Head of AI — main.js
   Scroll-reveal, mobile nav, smooth scroll,
   reading progress, FAQ open-state.
   ========================================= */

(function () {
  'use strict';

  // ---------- Scroll-reveal via IntersectionObserver ----------
  const revealEls = document.querySelectorAll('.fade-up, .fade-in');
  if (revealEls.length && 'IntersectionObserver' in window) {
    const io = new IntersectionObserver((entries, observer) => {
      entries.forEach((entry) => {
        if (entry.isIntersecting) {
          entry.target.classList.add('is-visible');
          observer.unobserve(entry.target);
        }
      });
    }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });
    revealEls.forEach((el) => io.observe(el));
  } else {
    // Fallback: show everything
    revealEls.forEach((el) => el.classList.add('is-visible'));
  }

  // ---------- Mobile nav toggle ----------
  const menuToggle = document.querySelector('[data-menu-toggle]');
  const mobileMenu = document.querySelector('[data-mobile-menu]');
  const menuOpenIcon = document.querySelector('[data-menu-open]');
  const menuCloseIcon = document.querySelector('[data-menu-close]');

  if (menuToggle && mobileMenu) {
    const setMenu = (open) => {
      mobileMenu.classList.toggle('is-open', open);
      document.body.style.overflow = open ? 'hidden' : '';
      if (menuOpenIcon) menuOpenIcon.style.display = open ? 'none' : 'block';
      if (menuCloseIcon) menuCloseIcon.style.display = open ? 'block' : 'none';
      menuToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    };

    menuToggle.addEventListener('click', () => {
      const isOpen = mobileMenu.classList.contains('is-open');
      setMenu(!isOpen);
    });

    // close on link click
    mobileMenu.querySelectorAll('a').forEach((link) => {
      link.addEventListener('click', () => setMenu(false));
    });

    // close on Escape
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && mobileMenu.classList.contains('is-open')) {
        setMenu(false);
      }
    });
  }

  // ---------- Reading progress bar (article pages) ----------
  const progressBar = document.querySelector('[data-reading-progress]');
  if (progressBar) {
    const updateProgress = () => {
      const scrollTop = window.scrollY;
      const docHeight = document.documentElement.scrollHeight - window.innerHeight;
      const pct = docHeight > 0 ? Math.min(100, (scrollTop / docHeight) * 100) : 0;
      progressBar.style.width = pct + '%';
    };
    window.addEventListener('scroll', updateProgress, { passive: true });
    updateProgress();
  }

  // ---------- Smooth-scroll for in-page anchors (with nav offset) ----------
  document.querySelectorAll('a[href^="#"]').forEach((anchor) => {
    anchor.addEventListener('click', function (e) {
      const href = this.getAttribute('href');
      if (href === '#' || href.length < 2) return;
      const target = document.querySelector(href);
      if (!target) return;
      e.preventDefault();
      const navHeight = 64;
      const top = target.getBoundingClientRect().top + window.scrollY - navHeight - 12;
      window.scrollTo({ top, behavior: 'smooth' });
    });
  });

  // ---------- Auto-update copyright year ----------
  const yearEl = document.querySelector('[data-year]');
  if (yearEl) yearEl.textContent = new Date().getFullYear();
})();
