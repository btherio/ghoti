(function () {
  'use strict';

  var menu = document.getElementById('workspace-menu');
  var header = document.querySelector('.cyber-header');
  var progress = document.querySelector('.scroll-progress__bar');
  var clock = document.getElementById('cyber-clock');
  var grid = document.querySelector('.cyber-grid');
  var reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  function updateHeaderMetrics() {
    if (!header) return;
    document.documentElement.style.setProperty('--header-offset', header.getBoundingClientRect().height + 'px');
  }

  function updateScroll() {
    var available = document.documentElement.scrollHeight - window.innerHeight;
    var percent = available > 0 ? Math.min(100, Math.max(0, window.scrollY / available * 100)) : 0;
    if (progress) progress.style.width = percent + '%';
    if (header) header.classList.toggle('is-scrolled', window.scrollY > 18);
  }

  function updateClock() {
    if (!clock) return;
    clock.textContent = new Date().toLocaleTimeString([], { hour12: false });
  }

  if (menu) {
    var trigger = menu.querySelector('summary');
    var signin = document.getElementById('account-signin');
    var avatar = menu.querySelector('.account-avatar');
    var accountInitial = avatar ? avatar.textContent : 'C';
    var account = menu.querySelector('#ghotiLogin');
    var admin = document.getElementById('ghotiAdminMenu');
    var privatePages = document.getElementById('ghotiPrivateMenu');
    var search = document.getElementById('workspace-search');
    var empty = document.getElementById('workspace-empty');

    function filterTools() {
      if (!search || !admin || !empty) return;
      var term = search.value.trim().toLowerCase();
      var matches = 0;
      admin.querySelectorAll('li').forEach(function (item) {
        item.hidden = item.textContent.toLowerCase().indexOf(term) === -1;
        if (!item.hidden) matches++;
      });
      empty.hidden = matches > 0 || !term;
    }

    function syncAccount() {
      var loggedIn = !!(account && account.querySelector('ul a'));
      var isAdmin = loggedIn && !!(admin && admin.querySelector('a'));
      var appearance = document.getElementById('workspace-appearance');
      menu.hidden = !loggedIn && !appearance;
      menu.toggleAttribute('data-guest', !loggedIn);
      if (avatar) avatar.textContent = loggedIn ? accountInitial : '◐';
      if (trigger) trigger.title = loggedIn ? 'Open your workspace' : 'Change appearance';
      var accountSection = menu.querySelector('.workspace-account');
      if (accountSection) accountSection.hidden = !loggedIn;
      if (signin) signin.hidden = loggedIn || signin.getAttribute('data-login-visible') === 'false';
      var adminSection = document.getElementById('workspace-admin');
      var privateSection = document.getElementById('workspace-private');
      if (adminSection) adminSection.hidden = !isAdmin;
      if (privateSection) privateSection.hidden = !loggedIn || !(privatePages && privatePages.querySelector('a'));
      var label = document.getElementById('workspace-trigger-label');
      if (label) label.textContent = isAdmin ? 'Console' : (loggedIn ? 'Account' : 'Theme');
      if (!loggedIn) menu.open = false;
      filterTools();
    }

    function closeMenu(restoreFocus) {
      menu.open = false;
      if (restoreFocus && trigger) trigger.focus();
    }

    menu.addEventListener('toggle', function () {
      if (trigger) trigger.setAttribute('aria-expanded', String(menu.open));
      if (!menu.open && search) { search.value = ''; filterTools(); }
    });
    var close = menu.querySelector('.workspace-close');
    if (close) close.addEventListener('click', function () { closeMenu(true); });
    document.addEventListener('click', function (event) {
      if (!menu.contains(event.target)) closeMenu(false);
      else if (event.target.closest('a')) closeMenu(false);
    });
    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && menu.open) { event.preventDefault(); closeMenu(true); }
      if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k' && !menu.hidden) {
        event.preventDefault();
        menu.open = !menu.open;
        if (menu.open && search && document.getElementById('workspace-admin') && !document.getElementById('workspace-admin').hidden) search.focus();
        else if (trigger) trigger.focus();
      }
    });
    if (search) search.addEventListener('input', filterTools);
    if (window.MutationObserver) {
      var observer = new MutationObserver(syncAccount);
      [account, admin, privatePages].forEach(function (node) {
        if (node) observer.observe(node, { childList: true, subtree: true });
      });
    }
    syncAccount();
    if (trigger) trigger.setAttribute('aria-expanded', 'false');
  }

  if (!reducedMotion && grid) {
    window.addEventListener('pointermove', function (event) {
      var x = (event.clientX / window.innerWidth - 0.5) * -10;
      var y = (event.clientY / window.innerHeight - 0.5) * -10;
      grid.style.transform = 'translate3d(' + x + 'px,' + y + 'px,0)';
    }, { passive: true });
  }

  window.addEventListener('resize', updateHeaderMetrics);
  window.addEventListener('scroll', updateScroll, { passive: true });
  if (window.ResizeObserver && header) new ResizeObserver(updateHeaderMetrics).observe(header);
  updateHeaderMetrics();
  updateScroll();
  updateClock();
  window.setInterval(updateClock, 1000);
})();
