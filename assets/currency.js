/* The landing page's currency switcher. Display only: SureCart bills in Rand
   and carries its own currency control on the checkout, so nothing here
   touches a checkout URL or an amount that will be charged.

   Every price on the page is printed by PHP in Rand and carries that figure on
   a data-money attribute. This converts them in place, which means the page is
   correct before this file has run and stays correct if it never does. No
   framework, no build step — the same approach as landing.js. */
(function () {
  'use strict';

  var BASE = 'ZAR';
  var STORE = 'afristream-currency';

  /* Kept in step with AFRISTREAM_CURRENCIES in includes/currency.php. */
  var CURRENCIES = {
    GBP: { symbol: '£', step: 1, group: true },
    NAD: { symbol: 'N$', step: 1, group: false },
    ZAR: { symbol: 'R', step: 1, group: false },
    USD: { symbol: '$', step: 1, group: true },
    VND: { symbol: '₫', step: 1000, group: true }
  };

  /* Kept in step with AFRISTREAM_FX_FALLBACK. Used until the page hands us a
     live table, and whenever the one it hands us cannot price everything. */
  var FALLBACK = { GBP: 0.043, NAD: 1, ZAR: 1, USD: 0.055, VND: 1420 };

  /* Where the visitor is, by timezone. Location is what the switcher is meant
     to follow, and a timezone is the only thing the browser will tell us about
     it — a language tag says which language they read, which is a different
     question and often answered "en-GB" on a machine in Johannesburg. */
  var BY_ZONE = {
    'Europe/London': 'GBP',
    'Asia/Ho_Chi_Minh': 'VND',
    'Asia/Saigon': 'VND'
  };

  /* South Africa and Namibia share an offset and neither keeps DST, and Chrome
     resolves the pair to Africa/Windhoek on plenty of Windows machines whose
     clock is set to South Africa. So the zone alone cannot separate them: the
     language tag decides when it names one of the two, and the Rand wins
     otherwise — it is the currency the site bills in, and by far the more
     likely visitor. The two are pegged one-for-one, so the figure on screen is
     the same either way; only the symbol moves. */
  var SHARED_ZONE = { 'Africa/Johannesburg': true, 'Africa/Windhoek': true };

  /* Second guess, from the region in a language tag. */
  var BY_COUNTRY = { ZA: 'ZAR', NA: 'NAD', GB: 'GBP', VN: 'VND', US: 'USD' };

  var rates = FALLBACK;
  var code = BASE;
  var listeners = [];

  // ------------------------------------------------------------- rates

  function usable(table) {
    if (!table) return false;
    for (var key in CURRENCIES) {
      if (!(table[key] > 0)) return false;
    }
    return true;
  }

  function readRates() {
    var host = document.querySelector('[data-fx-rates]');
    if (!host) return;
    try {
      var parsed = JSON.parse(host.getAttribute('data-fx-rates'));
      if (usable(parsed)) rates = parsed;
    } catch (e) {
      /* A malformed table is the same as no table: keep the baked-in one. */
    }
  }

  // --------------------------------------------------------- detection

  /* Both wrapped: storage throws outright in a locked-down browser rather than
     returning null, and a switcher that cannot remember a choice is still a
     working switcher. */
  function remembered() {
    try {
      return window.localStorage.getItem(STORE);
    } catch (e) {
      return null;
    }
  }

  function remember(value) {
    try {
      window.localStorage.setItem(STORE, value);
    } catch (e) {
      /* Nothing to do — the choice lasts this visit instead. */
    }
  }

  function fromZone() {
    var zone;
    try {
      zone = Intl.DateTimeFormat().resolvedOptions().timeZone;
    } catch (e) {
      return null;
    }
    if (SHARED_ZONE[zone]) return fromLanguage() === 'NAD' ? 'NAD' : 'ZAR';
    return BY_ZONE[zone] || null;
  }

  function fromLanguage() {
    var tags = navigator.languages && navigator.languages.length
      ? navigator.languages
      : [navigator.language];

    for (var i = 0; i < tags.length; i++) {
      var match = /[-_]([A-Za-z]{2})(?:[-_]|$)/.exec(tags[i] || '');
      if (match && BY_COUNTRY[match[1].toUpperCase()]) return BY_COUNTRY[match[1].toUpperCase()];
    }
    return null;
  }

  /* Anywhere we do not recognise is quoted in Dollars rather than in Rand: an
     unplaced visitor is more likely to be able to read a Dollar price, and the
     billed-in-Rand note tells them what they will actually be charged. */
  function detect() {
    var saved = remembered();
    if (saved && CURRENCIES[saved]) return saved;
    return fromZone() || fromLanguage() || 'USD';
  }

  // ------------------------------------------------------- formatting

  function group(n) {
    return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  }

  function format(rand, to) {
    to = to && CURRENCIES[to] ? to : code;
    var currency = CURRENCIES[to];
    var converted = Number(rand) * (rates[to] || 1);
    var rounded = Math.round(converted / currency.step) * currency.step;
    return currency.symbol + (currency.group ? group(rounded) : String(rounded));
  }

  // ---------------------------------------------------------- applying

  function each(selector, fn) {
    Array.prototype.slice.call(document.querySelectorAll(selector)).forEach(fn);
  }

  function apply() {
    each('[data-money]', function (el) {
      el.textContent = format(el.getAttribute('data-money'));
    });
    each('[data-fx-note]', function (el) {
      el.hidden = code === BASE;
    });
    // Both switchers show the same currency, whichever one was used.
    each('[data-fx-select]', function (el) {
      if (el.value !== code) el.value = code;
    });
    listeners.forEach(function (fn) {
      fn(code);
    });
  }

  function set(next, keep) {
    if (!CURRENCIES[next] || next === code) return;
    code = next;
    if (keep) remember(next);
    apply();
  }

  document.addEventListener('change', function (e) {
    var select = e.target.closest ? e.target.closest('[data-fx-select]') : null;
    if (select) set(select.value, true);
  });

  /* Read by onboarding.js, which prices its own breakdown lines in script
     rather than from markup this file can rewrite. */
  window.AfriStreamMoney = {
    format: format,
    current: function () {
      return code;
    },
    onChange: function (fn) {
      listeners.push(fn);
    }
  };

  function start() {
    readRates();
    code = detect();
    apply();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();
