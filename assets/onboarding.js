/* The onboarding flow behind the landing page's Get Started buttons. A step
   machine over the panels rendered by includes/onboarding.php. Every number it
   needs is on the root's data attributes. Nothing is stored: the only output
   is which checkout URL the last button opens. No framework, no build step —
   the same approach as landing.js. */
(function () {
  'use strict';

  var root = document.querySelector('[data-testid="onboarding"]');
  if (!root) return;

  var panel = root.querySelector('.as-ob-panel');
  var opener = null;

  var titleEl = root.querySelector('[data-testid="ob-title"]');
  var progressEl = root.querySelector('[data-testid="ob-progress"]');
  var nextBtn = root.querySelector('[data-ob-next]');
  var backBtn = root.querySelector('[data-ob-back]');
  var sums = window.AfriStreamSavings;

  var fee = Number(root.getAttribute('data-setup-fee')) || 0;

  var TITLES = {
    intro: 'What you get with AfriStream',
    device: 'Do you already have a streaming device?',
    subs: 'What do you pay for today?',
    offer: 'Shall we sort the device out for you?',
    breakdown: 'Here is what that comes to'
  };

  var state = { device: null, subs: 0, other: 0, wantsDevice: null };
  var at = 0;

  /* The steps this customer will actually see. Someone with a device is never
     offered one, and an install that has not priced the fee is not selling it —
     so the counter must not promise a step the flow then skips. */
  function steps() {
    var list = ['intro', 'device', 'subs'];
    if (state.device !== 'yes' && fee > 0) list.push('offer');
    list.push('breakdown');
    return list;
  }

  /* Whether the current step has been answered well enough to move on. The
     subscriptions question is deliberately not on this list: it improves the
     breakdown, it does not gate the purchase. */
  function ready(name) {
    if (name === 'device') return state.device !== null;
    if (name === 'offer') return state.wantsDevice !== null;
    return true;
  }

  function render() {
    var list = steps();
    var name = list[at];

    Array.prototype.slice.call(root.querySelectorAll('[data-ob-step]')).forEach(function (el) {
      el.hidden = el.getAttribute('data-ob-step') !== name;
    });

    titleEl.textContent = TITLES[name];
    progressEl.textContent = 'Step ' + (at + 1) + ' of ' + list.length;
    backBtn.hidden = at === 0;
    nextBtn.hidden = name === 'breakdown';
    nextBtn.disabled = !ready(name);
  }

  function go(delta) {
    var list = steps();
    at = Math.min(Math.max(at + delta, 0), list.length - 1);
    render();
  }

  function reset() {
    state = { device: null, subs: 0, other: 0, wantsDevice: null };
    at = 0;
    Array.prototype.slice.call(root.querySelectorAll('input[type="radio"]')).forEach(function (el) {
      el.checked = false;
    });
    Array.prototype.slice.call(root.querySelectorAll('[data-sub-price]')).forEach(function (el) {
      el.setAttribute('aria-pressed', 'false');
    });
    var other = root.querySelector('[data-testid="ob-subs-other"]');
    if (other) other.value = '';
    recalcSubs();
    render();
  }

  var subsStep = root.querySelector('[data-ob-step="subs"]');
  var subsTotalEl = root.querySelector('[data-testid="ob-subs-total"]');
  var otherEl = root.querySelector('[data-testid="ob-subs-other"]');

  function recalcSubs() {
    if (!subsStep) return;
    state.other = Number(otherEl && otherEl.value) || 0;
    state.subs = sums.total(subsStep, state.other);
    if (subsTotalEl) subsTotalEl.textContent = sums.money(state.subs);
  }

  root.addEventListener('click', function (e) {
    var chip = e.target.closest('[data-sub-price]');
    if (chip) {
      chip.setAttribute('aria-pressed', chip.getAttribute('aria-pressed') === 'true' ? 'false' : 'true');
      recalcSubs();
      return;
    }
    if (e.target.closest('[data-ob-next]')) go(1);
    if (e.target.closest('[data-ob-back]')) go(-1);
  });

  root.addEventListener('change', function (e) {
    if (e.target.name === 'as-ob-device') state.device = e.target.value;
    if (e.target.name === 'as-ob-offer') state.wantsDevice = e.target.value;
    render();
  });

  if (otherEl) otherEl.addEventListener('input', recalcSubs);

  var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])';

  function focusable() {
    return Array.prototype.slice.call(panel.querySelectorAll(FOCUSABLE))
      .filter(function (el) { return el.offsetParent !== null; });
  }

  function open(from) {
    opener = from || null;
    root.hidden = false;
    document.body.style.overflow = 'hidden';
    reset();
    panel.focus();
  }

  function close() {
    root.hidden = true;
    document.body.style.overflow = '';
    // Returning focus to the button that opened the flow: without this a
    // keyboard user is dropped back at the top of the document, having lost
    // the place they were reading.
    if (opener) opener.focus();
    opener = null;
  }

  // The href stays on every CTA so the page still buys with this script
  // absent. Intercepting the click is what turns it into the flow.
  document.addEventListener('click', function (e) {
    var cta = e.target.closest('[data-onboard]');
    if (cta) {
      e.preventDefault();
      open(cta);
      return;
    }
    if (e.target.closest('[data-ob-close]')) close();
  });

  document.addEventListener('keydown', function (e) {
    if (root.hidden) return;

    if (e.key === 'Escape' || e.key === 'Esc') {
      close();
      return;
    }

    // Trap: tabbing off either end of the panel wraps rather than landing on
    // the page behind, which is still scrolled to wherever they clicked.
    if (e.key !== 'Tab') return;
    var items = focusable();
    if (!items.length) return;
    var first = items[0];
    var last = items[items.length - 1];
    var active = document.activeElement;

    if (e.shiftKey && (active === first || active === panel)) {
      e.preventDefault();
      last.focus();
    } else if (!e.shiftKey && active === last) {
      e.preventDefault();
      first.focus();
    }
  });

  render();
})();
