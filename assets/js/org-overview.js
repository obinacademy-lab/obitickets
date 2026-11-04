/* Organizer dashboard home: the revenue charts, drawn from the figures the page embeds, with a hover readout.
   Plain JS, no dependencies. */
(function () {
  'use strict';

  var dataEl = document.getElementById('ovData');
  if (!dataEl) return;
  var data = JSON.parse(dataEl.textContent);
  var MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  var DAY = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

  function parse(s) { var p = s.split('-'); return new Date(+p[0], +p[1] - 1, +p[2]); }
  function dlabel(s) { var d = parse(s); return DAY[d.getDay()] + ' ' + d.getDate() + ' ' + MON[d.getMonth()]; }
  function short(x) { return x >= 1e6 ? +(x / 1e6).toFixed(1) + 'M' : x >= 1e3 ? +(x / 1e3).toFixed(1) + 'K' : String(Math.round(x)); }
  function money(x) { return data.currency + ' ' + Math.round(x).toLocaleString('en-US'); }

  function draw(box) {
    var compare = box.hasAttribute('data-compare');
    var width = box.getBoundingClientRect().width;
    if (!width) return;
    var narrow = width < 460;
    var W = narrow ? 360 : 720, H = parseInt(box.getAttribute('data-h'), 10) || 220;
    if (narrow) H = Math.round(H * 0.9);
    var pl = 44, pr = 8, pt = 10, pb = 22;
    var cur = data.cur.map(function (x) { return x.g; }), prv = data.prev.map(function (x) { return x.g; });
    var max = Math.max.apply(null, cur.concat(compare ? prv : [0])) * 1.1 || 1;

    // four equal steps of a round size, so the axis reads 500K, 1M, 1.5M and never 2.3M
    var raw = max / 4, pw = Math.pow(10, Math.floor(Math.log(raw) / Math.LN10));
    var step = [1, 2, 2.5, 4, 5, 10].filter(function (m) { return m * pw >= raw; })[0] * pw, top = step * 4;

    function X(i) { return pl + i * (W - pl - pr) / Math.max(1, cur.length - 1); }
    function Y(v) { return pt + (1 - v / top) * (H - pt - pb); }
    function path(arr) {
      return arr.map(function (v, i) {
        var x = X(i), y = Y(v);
        if (i === 0) return 'M' + x.toFixed(1) + ',' + y.toFixed(1);
        var px = X(i - 1), py = Y(arr[i - 1]), cx = (px + x) / 2;
        return 'C' + cx.toFixed(1) + ',' + py.toFixed(1) + ' ' + cx.toFixed(1) + ',' + y.toFixed(1) + ' ' + x.toFixed(1) + ',' + y.toFixed(1);
      }).join(' ');
    }
    var line = path(cur);
    var area = line + ' L' + X(cur.length - 1).toFixed(1) + ',' + (H - pb) + ' L' + X(0).toFixed(1) + ',' + (H - pb) + ' Z';
    var grid = '';
    for (var t = 0; t <= 4; t++) {
      var v = step * t;
      grid += '<line class="gl" x1="' + pl + '" x2="' + (W - pr) + '" y1="' + Y(v).toFixed(1) + '" y2="' + Y(v).toFixed(1) + '"/><text x="' + (pl - 8) + '" y="' + (Y(v) + 3).toFixed(1) + '" text-anchor="end">' + short(v) + '</text>';
    }
    var xl = '';
    [0, 0.25, 0.5, 0.75, 1].forEach(function (f) {
      var i = Math.round(f * (cur.length - 1)), d = parse(data.cur[i].d);
      xl += '<text x="' + X(i).toFixed(1) + '" y="' + (H - 4) + '" text-anchor="' + (f === 0 ? 'start' : f === 1 ? 'end' : 'middle') + '">' + d.getDate() + ' ' + MON[d.getMonth()] + '</text>';
    });
    var id = 'ovg' + Math.random().toString(36).slice(2, 7);
    box.innerHTML = '<svg viewBox="0 0 ' + W + ' ' + H + '" aria-hidden="true"><defs><linearGradient class="lg" id="' + id + '" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-opacity=".22"/><stop offset="1" stop-opacity="0"/></linearGradient></defs><g class="ax">' + grid + xl + '</g>' +
      (compare ? '<path class="prv" d="' + path(prv) + '"/>' : '') +
      '<path class="area" d="' + area + '" fill="url(#' + id + ')"/><path class="cur" d="' + line + '"/><line class="hv" y1="' + pt + '" y2="' + (H - pb) + '"/><circle class="dot" r="4.5"/></svg><div class="ov-tip"></div>';

    var svg = box.querySelector('svg'), tip = box.querySelector('.ov-tip'), hv = box.querySelector('.hv'), dot = box.querySelector('.dot');
    box.onpointermove = function (e) {
      var rect = svg.getBoundingClientRect(), x = (e.clientX - rect.left) / rect.width * W;
      var i = Math.max(0, Math.min(cur.length - 1, Math.round((x - pl) / (W - pl - pr) * (cur.length - 1))));
      var px = X(i), py = Y(cur[i]);
      hv.setAttribute('x1', px); hv.setAttribute('x2', px); hv.style.opacity = 0.35;
      dot.setAttribute('cx', px); dot.setAttribute('cy', py); dot.style.opacity = 1;
      var extra = '';
      if (compare) {
        extra = ' &middot; ' + (prv[i] > 0 ? (cur[i] >= prv[i] ? '+' : '') + Math.round((cur[i] - prv[i]) / prv[i] * 100) + '% vs ' + dlabel(data.prev[i].d) : money(prv[i]) + ' on ' + dlabel(data.prev[i].d));
      }
      tip.innerHTML = '<b>' + money(cur[i]) + '</b><span>' + dlabel(data.cur[i].d) + extra + '</span>';
      var tx = px / W * rect.width, tw = tip.offsetWidth;
      tip.style.left = Math.max(0, Math.min(rect.width - tw, tx - tw / 2)) + 'px';
      tip.style.top = Math.max(0, py / H * rect.height - tip.offsetHeight - 12) + 'px';
      tip.classList.add('on');
    };
    box.onpointerleave = function () { tip.classList.remove('on'); hv.style.opacity = 0; dot.style.opacity = 0; };
  }

  var boxes = [].slice.call(document.querySelectorAll('[data-chart]'));
  boxes.forEach(draw);
  if (typeof ResizeObserver !== 'undefined') {
    boxes.forEach(function (box) {
      var last = box.clientWidth;
      new ResizeObserver(function () { if (Math.abs(box.clientWidth - last) > 30) { last = box.clientWidth; draw(box); } }).observe(box);
    });
  }
})();
