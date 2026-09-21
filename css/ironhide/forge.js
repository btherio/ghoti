/* A little forge magic. Decorative only; navigation works without JavaScript. */
(function () {
  'use strict';
  var hero = document.querySelector('.ironhide-hero');
  var entrance = document.querySelector('.ironhide-hero-link');
  var content = document.getElementById('ironhide-content');
  if (!hero || !entrance || !content) return;
  var reduced = window.matchMedia('(prefers-reduced-motion: reduce)');
  var pointer = window.matchMedia('(hover: hover) and (pointer: fine)');
  var frame = 0;
  var timer = 0;
  var x = 50;
  var y = 50;
  function clearGlow() {
    cancelAnimationFrame(frame);
    frame = 0;
    hero.classList.remove('is-awake');
  }
  hero.addEventListener('pointermove', function (event) {
    if (reduced.matches || !pointer.matches) return;
    var bounds = hero.getBoundingClientRect();
    x = (event.clientX - bounds.left) / bounds.width * 100;
    y = (event.clientY - bounds.top) / bounds.height * 100;
    if (frame) return;
    frame = requestAnimationFrame(function () {
      hero.style.setProperty('--forge-x', x + '%');
      hero.style.setProperty('--forge-y', y + '%');
      hero.classList.add('is-awake');
      frame = 0;
    });
  });
  hero.addEventListener('pointerleave', clearGlow);
  entrance.addEventListener('click', function () {
    if (reduced.matches) return;
    clearTimeout(timer);
    content.classList.add('is-kindled');
    timer = setTimeout(function () { content.classList.remove('is-kindled'); }, 1500);
  });
  reduced.addEventListener('change', function () {
    clearGlow();
    clearTimeout(timer);
    content.classList.remove('is-kindled');
  });
})();
