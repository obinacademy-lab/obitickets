/* Pricing page: the "try your own ticket" calculator. The rate comes from the page (data-rate), which reads it
   from the code that charges it. Plain JS, no dependencies. */
(function () {
  'use strict';

  var box = document.getElementById('prCalc');
  if (!box) return;

  var rate = parseFloat(box.getAttribute('data-rate')) || 0;
  var label = box.getAttribute('data-rate-label') || String(rate * 100);
  var pct = parseFloat(label) || rate * 100;
  var priceEl = document.getElementById('prPrice'), qtyEl = document.getElementById('prQty'), freeEl = document.getElementById('prFree');
  var qty = 1;

  function $(id) { return document.getElementById(id); }
  function n(x) { return Math.round(x).toLocaleString('en-US'); }
  function price() { return parseInt((priceEl.value || '0').replace(/\D/g, ''), 10) || 0; }

  function update() {
    var p = price(), free = freeEl.checked;
    var gross = free ? 0 : p * qty, com = free ? 0 : Math.round(gross * rate), bal = gross - com;
    $('prGross').textContent = 'UGX ' + n(gross);
    $('prCom').innerHTML = (com ? '&minus; ' : '') + 'UGX ' + n(com);
    $('prBal').textContent = 'UGX ' + n(bal);
    $('prGl').textContent = free ? 'Free tickets, no price' : (qty > 1 ? 'Ticket sales (' + qty + ' tickets)' : 'Ticket sales');
    $('prCl').textContent = free ? 'Commission (free tickets: 0%)' : 'Commission (' + label + '%)';
    var keep = free ? 100 : 100 - pct;
    $('prKeep').style.width = keep + '%';
    $('prTake').style.width = (100 - keep) + '%';
    $('prKeepL').textContent = keep + '% to you';
    $('prTakeL').textContent = (free ? '0' : label) + '% commission';
    qtyEl.textContent = qty;
    [].forEach.call(box.querySelectorAll('[data-p]'), function (b) { b.setAttribute('aria-pressed', String(parseInt(b.getAttribute('data-p'), 10) === p)); });
  }

  priceEl.addEventListener('input', function () {
    var d = priceEl.value.replace(/\D/g, '').slice(0, 9);
    priceEl.value = d ? parseInt(d, 10).toLocaleString('en-US') : '';
    update();
  });
  box.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('button') : null;
    if (!b) return;
    if (b.hasAttribute('data-p')) { priceEl.value = parseInt(b.getAttribute('data-p'), 10).toLocaleString('en-US'); update(); }
    else if (b.hasAttribute('data-q')) { qty = Math.max(1, Math.min(999, qty + parseInt(b.getAttribute('data-q'), 10))); update(); }
  });
  freeEl.addEventListener('change', update);
  update();
})();
