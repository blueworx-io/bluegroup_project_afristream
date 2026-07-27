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
})();
