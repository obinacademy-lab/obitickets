/* Stage home page: the rotating billboard, the row arrows, the like hearts and the next-event countdown.
   Optional polish: with no script the first featured event shows, the rows still scroll, and every card is a link. */
(function () {
  'use strict';
  var calm = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ---------- billboard ---------- */
  var bill = document.getElementById('sgBill');
  if (bill) {
    var slides = bill.querySelectorAll('.sg-slide');
    var dots = bill.querySelectorAll('.sg-dots button');
    var cur = 0, timer = null;
    var show = function (n) {
      cur = (n + slides.length) % slides.length;
      Array.prototype.forEach.call(slides, function (s, i) { s.classList.toggle('on', i === cur); });
      Array.prototype.forEach.call(dots, function (d, i) { d.classList.remove('on'); void d.offsetWidth; d.classList.toggle('on', i === cur); });
    };
    var hold = false;
    var arm = function () {
      clearTimeout(timer);
      if (calm || slides.length < 2) return;
      timer = setTimeout(function () { if (!hold && !document.hidden) show(cur + 1); arm(); }, 6500);
    };
    Array.prototype.forEach.call(dots, function (d, i) { d.addEventListener('click', function () { show(i); arm(); }); });
    var pause = function (on) { hold = on; bill.classList.toggle('paused', on); };
    bill.addEventListener('mouseenter', function () { pause(true); });
    bill.addEventListener('mouseleave', function () { pause(false); });
    bill.addEventListener('focusin', function () { pause(true); });
    bill.addEventListener('focusout', function () { pause(false); });
    arm();
  }

  /* ---------- row arrows ---------- */
  Array.prototype.forEach.call(document.querySelectorAll('.sg-arrow'), function (a) {
    a.addEventListener('click', function () {
      var lane = document.getElementById(a.getAttribute('data-lane'));
      if (lane) lane.scrollBy({ left: parseInt(a.getAttribute('data-dir'), 10) * Math.round(lane.clientWidth * 0.8) });
    });
  });

  /* ---------- like hearts (same endpoint the event page uses; works signed out too) ---------- */
  var csrfMeta = document.querySelector('meta[name="csrf-token"]');
  Array.prototype.forEach.call(document.querySelectorAll('.sg-heart'), function (b) {
    b.addEventListener('click', function () {
      if (b.disabled) return;
      b.disabled = true;
      fetch('/api/toggle-like.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ eventId: parseInt(b.getAttribute('data-like'), 10), csrf_token: csrfMeta ? csrfMeta.content : '' })
      })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (d.error) return;
          b.setAttribute('aria-pressed', d.liked ? 'true' : 'false');
          b.innerHTML = d.liked ? '&#9829;' : '&#9825;';
        })
        .catch(function () {})
        .then(function () { b.disabled = false; });
    });
  });

  /* ---------- next-event countdown ---------- */
  var now = document.getElementById('sgNow');
  var cd = document.getElementById('sgCd');
  if (now && cd) {
    var start = parseInt(now.getAttribute('data-start'), 10) * 1000;
    var end = parseInt(now.getAttribute('data-end'), 10) * 1000;
    var tick = function () {
      var t = Date.now();
      if (t >= start && t <= end) { cd.textContent = 'Happening now'; return; }
      if (t > end) { cd.textContent = ''; return; }
      var m = Math.floor((start - t) / 60000), d = Math.floor(m / 1440), h = Math.floor((m % 1440) / 60), mm = m % 60;
      cd.textContent = (d > 0 ? d + 'd ' : '') + h + 'h ' + mm + 'm';
    };
    tick();
    setInterval(tick, 30000);
  }
})();
