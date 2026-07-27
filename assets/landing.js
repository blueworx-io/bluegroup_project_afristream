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
      total += Number(other && other.value) || 0;

      savingEl.textContent = money(Math.max(0, total - AFRISTREAM_PRICE));
      totalEl.textContent = money(total) + ' / year';
      basisEl.textContent = chosen === 0
        ? 'your selection below'
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
})();
