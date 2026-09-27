/* Subtle signal tracking for the hero. The theme remains complete without it. */
(function () {
  'use strict';
  var hero = document.querySelector('.hianxiety-hero');
  var logo = hero ? hero.querySelector('h1') : null;
  var reduced = window.matchMedia('(prefers-reduced-motion: reduce)');
  var precisePointer = window.matchMedia('(hover: hover) and (pointer: fine)');
  var frame = 0;

  if (!hero || !logo) return;

  function resetSignal() {
    if (frame) cancelAnimationFrame(frame);
    frame = 0;
    hero.style.removeProperty('--signal-x');
    hero.style.removeProperty('--signal-y');
    logo.style.removeProperty('--logo-x');
    logo.style.removeProperty('--logo-y');
  }

  hero.addEventListener('pointermove', function (event) {
    if (reduced.matches || !precisePointer.matches || frame) return;
    var bounds = hero.getBoundingClientRect();
    var x = (event.clientX - bounds.left) / bounds.width;
    var y = (event.clientY - bounds.top) / bounds.height;
    frame = requestAnimationFrame(function () {
      hero.style.setProperty('--signal-x', (x * 100).toFixed(1) + '%');
      hero.style.setProperty('--signal-y', (y * 100).toFixed(1) + '%');
      logo.style.setProperty('--logo-x', ((x - .5) * 9).toFixed(1) + 'px');
      logo.style.setProperty('--logo-y', ((y - .5) * 7).toFixed(1) + 'px');
      frame = 0;
    });
  });
  hero.addEventListener('pointerleave', resetSignal);
  reduced.addEventListener('change', resetSignal);
})();
