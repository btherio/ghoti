/* Fictional archive instruments. All content and navigation work without JS. */
(function () {
  'use strict';
  var focus = document.getElementById('veil-focus');
  focus.hidden = false;
  focus.addEventListener('click', function () {
    var active = document.body.classList.toggle('veil-reading');
    focus.setAttribute('aria-pressed', String(active));
    focus.textContent = active ? 'Restore exhibits' : 'Reading lamp';
  });
  var lenses = {
    geometry: ['As above. So below.', 'Geometry turns a mystery into a question worth asking.'],
    cosmos: ['Beyond the familiar.', 'An imagined observer, reflected across more than one dimension.'],
    grove: ['The old paths remain.', 'Oak, stone, and interwoven lines: a symbolic map of the living world.']
  };
  document.querySelectorAll('[data-lens]').forEach(function (button) {
    button.addEventListener('click', function () {
      var key = button.dataset.lens;
      document.querySelector('.veil-instrument').dataset.lens = key;
      document.getElementById('veil-instrument-title').textContent = lenses[key][0];
      document.getElementById('veil-lens-description').textContent = lenses[key][1];
      document.querySelectorAll('.veil-lenses button').forEach(function (item) {
        item.setAttribute('aria-pressed', String(item === button));
      });
    });
  });
})();
