// Interaction layer for the "cinematic" dark-page treatment (body.dark-page
// in style.css) — shared by every page that opts into it (about.php,
// pricing.php, ...). Purely class-driven (.reveal/.split-heading/.curtain/
// .tilt-card/.magnetic/[data-count]/.parallax), nothing page-specific here.
(function () {
  var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // ---- split headline into words ----
  document.querySelectorAll('[data-split]').forEach(function (el) {
    var words = el.textContent.split(' ');
    el.innerHTML = '<span class="line">' + words.map(function (w, i) {
      return '<span class="word" style="--i:' + i + '">' + w + '&nbsp;</span>';
    }).join('') + '</span>';
  });

  if (reduced || !('IntersectionObserver' in window)) {
    document.querySelectorAll('.reveal, .split-heading, .curtain').forEach(function (el) {
      el.classList.add('in');
    });
    return;
  }

  // ---- reveal + split-heading + curtain on scroll ----
  var revealEls = document.querySelectorAll('.reveal, .split-heading, .curtain');
  var io = new IntersectionObserver(function (entries) {
    entries.forEach(function (entry) {
      if (entry.isIntersecting) {
        entry.target.classList.add('in');
        io.unobserve(entry.target);
      }
    });
  }, { threshold: 0.2, rootMargin: '0px 0px -80px 0px' });
  revealEls.forEach(function (el) { io.observe(el); });

  // ---- count-up (any number of [data-count] elements on the page) ----
  document.querySelectorAll('[data-count]').forEach(function (counter) {
    var target = parseFloat(counter.getAttribute('data-count'));
    var prefix = counter.getAttribute('data-prefix') || '';
    var suffix = counter.getAttribute('data-suffix') || '';
    var decimals = parseInt(counter.getAttribute('data-decimals') || '0', 10);
    var done = false;
    var countIo = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting && !done) {
          done = true;
          var start = null, duration = 1000;
          function step(ts) {
            if (!start) start = ts;
            var p = Math.min((ts - start) / duration, 1);
            var eased = 1 - Math.pow(1 - p, 3);
            counter.textContent = prefix + (eased * target).toFixed(decimals) + suffix;
            if (p < 1) requestAnimationFrame(step);
          }
          requestAnimationFrame(step);
          countIo.unobserve(counter);
        }
      });
    }, { threshold: 0.6 });
    countIo.observe(counter);
  });

  // ---- scroll parallax on hero glow + tagged images ----
  var heroGlow = document.getElementById('heroGlow');
  var parallaxEls = Array.prototype.slice.call(document.querySelectorAll('.parallax'));
  var ticking = false;
  function onScroll() {
    if (!ticking) {
      requestAnimationFrame(function () {
        var y = window.scrollY;
        if (heroGlow) heroGlow.style.transform = 'translate(-50%, ' + (y * 0.25) + 'px)';
        parallaxEls.forEach(function (el) {
          var speed = parseFloat(el.getAttribute('data-speed')) || 0.1;
          var rect = el.getBoundingClientRect();
          var center = rect.top + rect.height / 2 - window.innerHeight / 2;
          var img = el.querySelector('img');
          if (img) img.style.transform = 'translateY(' + (center * speed * -1) + 'px) scale(1.15)';
        });
        ticking = false;
      });
      ticking = true;
    }
  }
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();

  // ---- 3D tilt: hero image + cards follow cursor ----
  function attachTilt(el, strength) {
    var rect;
    el.addEventListener('mouseenter', function () { rect = el.getBoundingClientRect(); });
    el.addEventListener('mousemove', function (e) {
      if (!rect) rect = el.getBoundingClientRect();
      var px = (e.clientX - rect.left) / rect.width - 0.5;
      var py = (e.clientY - rect.top) / rect.height - 0.5;
      el.style.transform = 'rotateY(' + (px * strength) + 'deg) rotateX(' + (py * -strength) + 'deg) translateY(-4px)';
    });
    el.addEventListener('mouseleave', function () {
      el.style.transform = 'rotateY(0deg) rotateX(0deg) translateY(0)';
    });
  }
  var tiltHero = document.getElementById('tiltHero');
  if (tiltHero) attachTilt(tiltHero, 10);
  document.querySelectorAll('.tilt-card').forEach(function (el) { attachTilt(el, 6); });

  // ---- magnetic buttons ----
  document.querySelectorAll('.magnetic').forEach(function (wrap) {
    var btn = wrap.querySelector('a');
    wrap.addEventListener('mousemove', function (e) {
      var r = wrap.getBoundingClientRect();
      var x = (e.clientX - r.left - r.width / 2) * 0.35;
      var y = (e.clientY - r.top - r.height / 2) * 0.5;
      btn.style.transform = 'translate(' + x + 'px,' + y + 'px)';
    });
    wrap.addEventListener('mouseleave', function () {
      btn.style.transform = 'translate(0,0)';
    });
  });
})();
