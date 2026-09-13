/* Theme-only account navigation; independent of animation/CDN libraries. */
(function () {
  'use strict';
  var menu = document.getElementById('workspace-menu');
  var trigger = menu.querySelector('summary');
  var signin = document.getElementById('account-signin');
  var avatar = menu.querySelector('.account-avatar');
  var accountInitial = avatar.textContent;
  var account = document.querySelector('#workspace-panel #ghotiLogin');
  var admin = document.getElementById('ghotiAdminMenu');
  var privatePages = document.getElementById('ghotiPrivateMenu');
  var search = document.getElementById('workspace-search');
  var empty = document.getElementById('workspace-empty');

  function filterTools() {
    var term = search.value.trim().toLowerCase();
    var matches = 0;
    admin.querySelectorAll('li').forEach(function (item) {
      item.hidden = item.textContent.toLowerCase().indexOf(term) === -1;
      if (!item.hidden) matches++;
    });
    empty.hidden = matches > 0 || !term;
  }
  function syncAccount() {
    var loggedIn = !!account.querySelector('ul a');
    var isAdmin = loggedIn && !!admin.querySelector('a');
    menu.hidden = !loggedIn && !document.getElementById('workspace-appearance');
    menu.toggleAttribute('data-guest', !loggedIn);
    avatar.textContent = loggedIn ? accountInitial : '◐';
    trigger.title = loggedIn ? 'Open your workspace' : 'Change appearance';
    menu.querySelector('.workspace-account').hidden = !loggedIn;
    signin.hidden = loggedIn || signin.getAttribute('data-login-visible') === 'false';
    document.getElementById('workspace-admin').hidden = !isAdmin;
    document.getElementById('workspace-private').hidden = !loggedIn || !privatePages.querySelector('a');
    document.getElementById('workspace-trigger-label').textContent = isAdmin ? 'Workspace' : (loggedIn ? 'Account' : 'Appearance');
    if (!loggedIn) menu.open = false;
    filterTools();
  }
  function closeMenu(restoreFocus) {
    menu.open = false;
    if (restoreFocus) trigger.focus();
  }
  menu.addEventListener('toggle', function () {
    trigger.setAttribute('aria-expanded', String(menu.open));
    if (!menu.open) { search.value = ''; filterTools(); }
  });
  menu.querySelector('.workspace-close').addEventListener('click', function () { closeMenu(true); });
  document.addEventListener('click', function (event) {
    if (!menu.contains(event.target)) closeMenu(false);
    else if (event.target.closest('a')) closeMenu(false);
  });
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && menu.open) { event.preventDefault(); closeMenu(true); }
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k' && !menu.hidden) {
      event.preventDefault(); menu.open = !menu.open;
      if (menu.open) {
        if (!document.getElementById('workspace-admin').hidden) search.focus();
        else trigger.focus();
      } else trigger.focus();
    }
  });
  search.addEventListener('input', filterTools);
  var observer = new MutationObserver(syncAccount);
  [account, admin, privatePages].forEach(function (node) { observer.observe(node, { childList: true, subtree: true }); });
  syncAccount();
  trigger.setAttribute('aria-expanded', 'false');
  var header = document.querySelector('.site-header');
  function sizeHeader() { document.documentElement.style.setProperty('--header-offset', header.getBoundingClientRect().height + 'px'); }
  if (window.ResizeObserver) new ResizeObserver(sizeHeader).observe(header);
  window.addEventListener('resize', sizeHeader);
  sizeHeader();
})();
