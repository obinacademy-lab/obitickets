/* Home page: the live countdown on the "Your next event" strip. Optional: without script the strip still shows the
   event, date and a View ticket button. */
(function () {
  'use strict';
  var box = document.getElementById('ldNext');
  var cd = document.getElementById('ldCd');
  if (!box || !cd) return;
  var start = parseInt(box.getAttribute('data-start'), 10) * 1000;
  var end = parseInt(box.getAttribute('data-end'), 10) * 1000;
  function tick() {
    var t = Date.now();
    if (t >= start && t <= end) { cd.textContent = 'Happening now'; return; }
    if (t > end) { cd.textContent = ''; return; }
    var m = Math.floor((start - t) / 60000), d = Math.floor(m / 1440), h = Math.floor((m % 1440) / 60), mm = m % 60;
    cd.textContent = (d > 0 ? d + 'd ' : '') + h + 'h ' + mm + 'm';
  }
  tick();
  setInterval(tick, 30000);
})();
