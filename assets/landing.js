/* AfriStream landing page. Four jobs: the mobile menu, the scroll reveal, the
   savings calculator and the FAQ accordion. Everything else is static HTML
   rendered by PHP. No framework, no build step — the same approach as
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

  // -------------------------------------------------------- calculator

  var calc = root.querySelector('[data-testid="landing-calculator"]');

  if (calc) {
    var AFRISTREAM_PRICE = 1599;
    var subs = Array.prototype.slice.call(calc.querySelectorAll('[data-sub-price]'));
    var other = calc.querySelector('[data-testid="calc-other"]');
    var savingEl = calc.querySelector('[data-testid="calc-saving"]');
    var totalEl = calc.querySelector('[data-testid="calc-total"]');
    var basisEl = calc.querySelector('[data-testid="calc-basis"]');

    // en-ZA groups thousands the way the rest of the page's prices read.
    function money(n) {
      return 'R' + n.toLocaleString('en-ZA');
    }

    function recalc() {
      var total = 0;
      var chosen = 0;
      subs.forEach(function (btn) {
        if (btn.getAttribute('aria-pressed') === 'true') {
          total += Number(btn.getAttribute('data-sub-price')) || 0;
          chosen += 1;
        }
      });
      var otherAmount = Number(other && other.value) || 0;
      total += otherAmount;

      savingEl.textContent = money(Math.max(0, total - AFRISTREAM_PRICE));
      totalEl.textContent = money(total) + ' / year';
      // No chips pressed but an "other" figure entered is still a non-zero
      // saving — "your selection below" would read as if nothing had been
      // chosen at all, right beside a number that says otherwise.
      basisEl.textContent = chosen === 0
        ? (otherAmount > 0 ? 'the other amount entered below' : 'your selection below')
        : chosen + ' subscription' + (chosen === 1 ? '' : 's') + ' selected';
    }

    subs.forEach(function (btn) {
      btn.addEventListener('click', function () {
        btn.setAttribute('aria-pressed', btn.getAttribute('aria-pressed') === 'true' ? 'false' : 'true');
        recalc();
      });
    });

    if (other) other.addEventListener('input', recalc);

    recalc();
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
