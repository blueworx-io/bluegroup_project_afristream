/* The savings arithmetic, shared by the landing page's calculator and the
   onboarding flow's breakdown. Kept in one place because the two show the same
   number to the same customer minutes apart, and a disagreement between them
   is worse than either being wrong alone. */
window.AfriStreamSavings = (function () {
  'use strict';

  // en-ZA groups thousands the way the rest of the page's prices read.
  function money(n) {
    return 'R' + Math.round(n).toLocaleString('en-ZA');
  }

  /** The pressed subscription chips inside a container. */
  function chosen(scope) {
    return Array.prototype.slice.call(
      scope.querySelectorAll('[data-sub-price][aria-pressed="true"]')
    );
  }

  /** What the customer spends a year: the pressed chips, plus any free amount. */
  function total(scope, other) {
    return chosen(scope).reduce(function (sum, el) {
      return sum + (Number(el.getAttribute('data-sub-price')) || 0);
    }, Number(other) || 0);
  }

  /** Never negative: someone spending less than AfriStream costs saves nothing,
      they do not owe the difference. */
  function saving(spend, price) {
    return Math.max(0, spend - price);
  }

  return { money: money, chosen: chosen, total: total, saving: saving };
})();
