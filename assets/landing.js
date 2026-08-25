/* AfriStream landing page. Four jobs: the mobile menu, the setup-guide device
   picker, the FAQ accordion and the scroll reveal. Everything else is static
   HTML rendered by PHP. No framework, no build step — the same approach as
   portal.js. */
(function () {
  'use strict';

  var root = document.querySelector('.as-landing');
  if (!root) return;

  // -------------------------------------------------------------- menu

  var burger = root.querySelector('[data-testid="landing-burger"]');
  var menu = root.querySelector('[data-testid="landing-menu"]');

  function setMenu(open) {
    if (!burger || !menu) return;
    menu.hidden = !open;
    burger.setAttribute('aria-expanded', open ? 'true' : 'false');
    var path = burger.querySelector('path');
    if (path) path.setAttribute('d', open ? 'M6 6l12 12M18 6L6 18' : 'M4 7h16M4 12h16M4 17h16');
  }

  if (burger) {
    burger.addEventListener('click', function () {
      setMenu(menu.hidden);
    });
  }

  if (menu) {
    // Choosing a destination closes the panel rather than leaving it over the
    // section just jumped to.
    menu.addEventListener('click', function (e) {
      if (e.target.closest('a')) setMenu(false);
    });
  }

  // Escape closes the panel and returns focus to the toggle — without this,
  // a keyboard user who opens the menu has no way to dismiss it without
  // tabbing all the way through every link inside it.
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape' && e.key !== 'Esc') return;
    if (!menu || menu.hidden) return;
    setMenu(false);
    if (burger) burger.focus();
  });

  // ------------------------------------------------------- setup guides

  /* The device picker is a tablist: one guide shows at a time, and every panel
     is in the markup rather than fetched. PHP renders the first one open and
     the rest hidden — same as the FAQ — so the section is never a flash of
     eight stacked guides before this runs, and a visitor without JavaScript
     still gets a complete guide rather than an empty box. */

  var picker = root.querySelector('.as-setup-picker');
  var tabs = picker
    ? Array.prototype.slice.call(picker.querySelectorAll('[data-setup-tab]'))
    : [];

  function showDevice(key) {
    tabs.forEach(function (tab) {
      var on = tab.getAttribute('data-setup-tab') === key;
      tab.setAttribute('aria-selected', on ? 'true' : 'false');
      // Roving tabindex: the tablist is one stop in the tab order, and the
      // arrow keys move within it.
      tab.setAttribute('tabindex', on ? '0' : '-1');
    });
    Array.prototype.slice.call(root.querySelectorAll('[data-setup-panel]')).forEach(function (panel) {
      panel.hidden = panel.getAttribute('data-setup-panel') !== key;
    });
  }

  if (tabs.length) {
    picker.addEventListener('click', function (e) {
      var tab = e.target.closest('[data-setup-tab]');
      if (tab) showDevice(tab.getAttribute('data-setup-tab'));
    });

    picker.addEventListener('keydown', function (e) {
      var step = e.key === 'ArrowRight' || e.key === 'ArrowDown' ? 1
        : e.key === 'ArrowLeft' || e.key === 'ArrowUp' ? -1
          : 0;
      if (!step) return;
      var i = tabs.indexOf(document.activeElement);
      if (i < 0) return;
      e.preventDefault();
      var next = tabs[(i + step + tabs.length) % tabs.length];
      showDevice(next.getAttribute('data-setup-tab'));
      next.focus();
    });
  }

  // --------------------------------------------------------------- faq

  root.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-faq] button');
    if (!btn || !root.contains(btn)) return;
    var answer = btn.parentNode.querySelector('[data-faq-answer]');
    if (!answer) return;
    var open = btn.getAttribute('aria-expanded') === 'true';
    btn.setAttribute('aria-expanded', open ? 'false' : 'true');
    answer.hidden = open;
  });

  // ------------------------------------------------------------ reveal

  // Sections fade and rise as they come into view. Anything already on screen
  // at load is never hidden — a reveal that hides the hero and then fails to
  // fire is worse than no reveal at all. Skipped entirely when the visitor
  // prefers reduced motion.
  var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  if (!reduced) {
    var pending = [];

    Array.prototype.slice.call(root.querySelectorAll('[data-reveal]')).forEach(function (el) {
      if (el.getBoundingClientRect().top < window.innerHeight) return;
      el.style.opacity = '0';
      el.style.transform = 'translateY(16px)';
      el.style.transition = 'opacity .25s ease-out, transform .25s ease-out';
      pending.push(el);
    });

    var check = function () {
      pending = pending.filter(function (el) {
        if (el.getBoundingClientRect().top >= window.innerHeight * 0.92) return true;
        el.style.opacity = '1';
        el.style.transform = 'none';
        return false;
      });
      if (!pending.length) {
        window.removeEventListener('scroll', check, true);
        window.removeEventListener('resize', check);
      }
    };

    // Capture, so a scroll on any container counts.
    window.addEventListener('scroll', check, true);
    window.addEventListener('resize', check);
    check();
  }
})();
