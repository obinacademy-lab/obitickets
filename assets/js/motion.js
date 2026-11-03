/* Motion layer helpers (see motion.css). Small and optional: if this script never runs, every page still reads and
   works exactly the same, just without the word-rise and the hero parallax. */
(function () {
  'use strict';
  var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (reduced) return;

  // Headlines marked data-rise: the words rise into place one after another.
  Array.prototype.forEach.call(document.querySelectorAll('[data-rise]'), function (el) {
    var text = el.textContent.replace(/\s+/g, ' ').trim();
    if (!text) return;
    el.setAttribute('aria-label', text);          // screen readers get the sentence, not a pile of spans
    el.textContent = '';
    text.split(' ').forEach(function (word, i) {
      var w = document.createElement('span'); w.className = 'mo-w'; w.setAttribute('aria-hidden', 'true');
      var s = document.createElement('span'); s.style.setProperty('--i', i); s.textContent = word;
      w.appendChild(s); el.appendChild(w); el.appendChild(document.createTextNode(' '));
    });
  });

  // Home hero: the poster stack drifts a little as the pointer moves (mouse only, never on touch).
  var hero = document.querySelector('.home-hero-v2');
  var collage = document.getElementById('heroCollage');
  if (hero && collage && window.matchMedia('(pointer: fine)').matches) {
    hero.addEventListener('pointermove', function (e) {
      var r = hero.getBoundingClientRect();
      collage.style.setProperty('--px', ((e.clientX - r.left) / r.width - 0.5).toFixed(3));
      collage.style.setProperty('--py', ((e.clientY - r.top) / r.height - 0.5).toFixed(3));
    });
    hero.addEventListener('pointerleave', function () {
      collage.style.setProperty('--px', 0);
      collage.style.setProperty('--py', 0);
    });
  }
})();
