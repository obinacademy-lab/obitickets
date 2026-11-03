// Contact page: plays the closing-line entrance once, when the photo plate scrolls into view.
// Without scripts, or when the browser has no IntersectionObserver, the plate simply shows as it is.
(function () {
  var plate = document.querySelector('.ct-plate');
  if (!plate) return;
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (reduce || !('IntersectionObserver' in window)) return;
  plate.classList.add('armed'); // hides the pieces until they are about to play, so nothing flashes first
  var io = new IntersectionObserver(function (entries) {
    entries.forEach(function (e) {
      if (e.isIntersecting) {
        plate.classList.remove('armed');
        plate.classList.add('play');
        io.unobserve(plate);
      }
    });
  }, { threshold: 0.3 });
  io.observe(plate);
})();
