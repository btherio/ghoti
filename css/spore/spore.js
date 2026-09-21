/* The garden's tiny inhabitants. No trackers, sound, or external libraries. */
(function () {
  'use strict';
  var motion = document.getElementById('spore-motion');
  var reduced = window.matchMedia('(prefers-reduced-motion: reduce)');
  var paused = reduced.matches;
  try { paused = paused || localStorage.getItem('spore-paused') === 'true'; } catch (_) { /* Storage is optional. */ }
  function syncMotion() {
    document.body.classList.toggle('spore-paused', paused || reduced.matches);
    motion.setAttribute('aria-pressed', String(paused || reduced.matches));
    motion.textContent = reduced.matches ? 'Motion reduced' : (paused ? 'Wake the cosmos' : 'Pause the cosmos');
    motion.disabled = reduced.matches;
  }
  motion.hidden = false;
  syncMotion();
  motion.addEventListener('click', function () {
    paused = !paused;
    try { localStorage.setItem('spore-paused', String(paused)); } catch (_) { /* Still works for this visit. */ }
    syncMotion();
  });
  reduced.addEventListener('change', syncMotion);
  var found = new Set();
  var messages = {
    fairy: '✧ The fairy whispers: “You are not lost. You are taking the scenic dimension.”',
    gnome: '🍄 The gnome says: “I maintain the root directory. It is mostly actual roots.”',
    reader: '✿ The page fairy says: “Someone planted an idea here. Thank you for helping it grow.”'
  };
  document.querySelectorAll('[data-secret]').forEach(function (button) {
    button.addEventListener('click', function () {
      var key = button.dataset.secret;
      found.add(key);
      var message = messages[key];
      if (found.size === 3) message += ' ✷ All three garden keepers found. You are officially one of the weird ones.';
      var status = document.getElementById('spore-discovery');
      status.textContent = message;
      // Keep the reading fairy's answer beside its trigger, without moving focus.
      var local = document.getElementById('spore-reader-reply');
      if (key === 'reader') {
        if (!local) {
          local = document.createElement('p');
          local.id = 'spore-reader-reply';
          local.className = 'spore-reader-reply';
          button.before(local);
        }
        local.textContent = message;
      }
      button.dataset.found = 'true';
    });
  });
})();
