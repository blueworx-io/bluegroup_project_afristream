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

  var costEl = root.querySelector('[data-testid="ob-cost"]');
  var totalEl = root.querySelector('[data-testid="ob-total"]');
  var checkoutEl = root.querySelector('[data-testid="ob-checkout"]');

  var fee = 0;
  var price = 0;

  /* The breakdown's figures are computed here rather than printed by PHP, so
     they are the one set of prices currency.js cannot rewrite from markup —
     they go through its formatter instead, and the fallback matches how PHP
     writes a Rand price when that file is absent. */
  function money(n) {
    return window.AfriStreamMoney ? window.AfriStreamMoney.format(n) : 'R' + Math.round(n);
  }

  var TITLES = {
    intro: 'What you get with AfriStream',
    device: 'Shall we source a device for you?',
    breakdown: 'Here is what that comes to'
  };

  var state = { wantsDevice: null };
  var at = 0;

  /* Whether the device offer is being sold at all: a priced fee alone is not
     enough. An admin who prices the setup fee but never sets a distinct setup
     checkout would otherwise have the flow itemise a fee the checkout it lands
     on cannot charge — the plain checkout, since data-setup-cta falls back to
     it. Treated the same as "no fee configured": nothing to offer. */
  function offerAvailable() {
    return fee > 0 && root.getAttribute('data-setup-cta') !== root.getAttribute('data-cta');
  }

  /* The steps this customer will actually see. One device question, and only
     where a device is actually being sold — an install that has not priced the
     fee, or has no checkout of its own to bill it through, drops straight from
     the intro to the total. */
  function steps() {
    var list = ['intro'];
    if (offerAvailable()) list.push('device');
    list.push('breakdown');
    return list;
  }

  /* Whether the current step has been answered well enough to move on. */
  function ready(name) {
    if (name === 'device') return state.wantsDevice !== null;
    return true;
  }

  function render() {
    var list = steps();
    var name = list[at];
    var last = name === 'breakdown';

    Array.prototype.slice.call(root.querySelectorAll('[data-ob-step]')).forEach(function (el) {
      el.hidden = el.getAttribute('data-ob-step') !== name;
    });

    titleEl.textContent = TITLES[name];
    progressEl.textContent = 'Step ' + (at + 1) + ' of ' + list.length;
    backBtn.hidden = at === 0;
    // Continue and the checkout link are the same button in the same place,
    // one swapped for the other on the last step: the flow's forward action
    // must never move down the panel just because it changed what it does.
    nextBtn.hidden = last;
    nextBtn.disabled = !ready(name);
    checkoutEl.hidden = !last;

    if (last) breakdown();

    // A keyboard user activates Continue with Enter, so it is
    // document.activeElement right as the two lines above may hide or disable
    // it — the browser blurs it to <body>, which both drops the focus trap
    // (the next Tab lands on the page behind the modal) and means the step
    // change is never announced to a screen reader. Moving focus to the
    // title on every step change fixes both: it is always present, never
    // hidden or disabled, and its rewritten text is what the step change is.
    if (!root.hidden) {
      var active = document.activeElement;
      if (!panel.contains(active) || active.disabled || active.hidden) {
        titleEl.focus();
      }
    }
  }

  function line(label, amount) {
    return '<li><span>' + label + '</span><span>' + money(amount) + '</span></li>';
  }

  function breakdown() {
    var takesDevice = state.wantsDevice === 'yes' && fee > 0;

    costEl.innerHTML = line('AfriStream, one year', price)
      + (takesDevice ? line('Device, sourced and set up — once off', fee) : '');
    totalEl.textContent = money(price + (takesDevice ? fee : 0));

    // The flow's whole output: which checkout this customer belongs at.
    checkoutEl.setAttribute(
      'href',
      takesDevice ? root.getAttribute('data-setup-cta') : root.getAttribute('data-cta')
    );
  }

  function go(delta) {
    var list = steps();
    at = Math.min(Math.max(at + delta, 0), list.length - 1);
    render();
  }

  function reset() {
    state = { wantsDevice: null };
    at = 0;
    Array.prototype.slice.call(root.querySelectorAll('input[type="radio"]')).forEach(function (el) {
      el.checked = false;
    });

    // Read per open, not once at load: a test — and a cached page whose
    // settings have since changed — can move the fee or the price under us.
    fee = Number(root.getAttribute('data-setup-fee')) || 0;
    // Printing "Total today R0" beside a live checkout button is worse than
    // falling back to the plugin's own default price.
    price = Number(root.getAttribute('data-price')) || 1599;

    render();
  }

  root.addEventListener('click', function (e) {
    if (e.target.closest('[data-ob-next]')) go(1);
    if (e.target.closest('[data-ob-back]')) go(-1);
  });

  /* Switching currency with the breakdown on screen has to repaint it: those
     lines were written into the DOM once, by breakdown(), and nothing else
     will touch them again. Skipped while the modal is shut, when price and fee
     are still zero — reset() reads them on every open anyway. */
  if (window.AfriStreamMoney) {
    window.AfriStreamMoney.onChange(function () {
      if (!root.hidden) render();
    });
  }

  root.addEventListener('change', function (e) {
    if (e.target.name === 'as-ob-device') state.wantsDevice = e.target.value;
    render();
  });

  var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])';

  function focusable() {
    return Array.prototype.slice.call(panel.querySelectorAll(FOCUSABLE))
      .filter(function (el) { return el.offsetParent !== null; });
  }

  // Restored on close rather than hard-reset to '': a page that already had
  // its own inline overflow style must get it back, not have it discarded.
  var savedOverflow = '';

  /* Everything the modal covers, taken out of the accessibility tree and out
     of reach of the pointer and the tab order while it is open. The focus trap
     handles Tab, but without this a screen reader can still browse the page
     behind a dialog that is meant to be the only thing on screen. Applied to
     the modal's siblings rather than to the page wrapper, because the modal is
     rendered inside that wrapper and would go inert with it. */
  function setBackgroundInert(inert) {
    var siblings = root.parentNode ? root.parentNode.children : [];
    Array.prototype.slice.call(siblings).forEach(function (el) {
      if (el === root) return;
      if (inert) {
        el.setAttribute('inert', '');
      } else {
        el.removeAttribute('inert');
      }
    });
  }

  function open(from) {
    opener = from || null;
    root.hidden = false;
    savedOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    setBackgroundInert(true);
    reset();
    panel.focus();
  }

  function close() {
    root.hidden = true;
    document.body.style.overflow = savedOverflow;
    // Before focus is returned: the opener is behind the modal, and focusing
    // an element inside an inert subtree does nothing.
    setBackgroundInert(false);
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
      // A modifier or a non-primary button means "open in a new tab/window",
      // not "start the flow" — preventDefault() here would silently break
      // that, on every Get Started link on the page.
      if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
      e.preventDefault();
      open(cta);
      return;
    }
    if (e.target.closest('[data-ob-close]')) close();

    // The terminal checkout button is a real link so the page still buys
    // with this script absent, but on an unconfigured install it resolves to
    // a same-page fragment (afristream_landing_cta_url()'s "#setup"
    // fallback). Left open, the modal's overflow:hidden and full-screen
    // backdrop mean that scroll happens invisibly behind it — the customer's
    // click reads as having done nothing. Closing first, without
    // preventDefault(), lets the browser's own jump land on a page the
    // customer can actually see.
    var checkout = e.target.closest('[data-testid="ob-checkout"]');
    if (checkout && (checkout.getAttribute('href') || '').charAt(0) === '#') close();
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
