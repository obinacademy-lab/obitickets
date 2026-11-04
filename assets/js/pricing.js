/* Pricing page: the "try your own ticket" calculator. The rate comes from the page (data-rate), which reads it
   from the code that charges it. Price can be typed or dragged; tickets sold is a slider. Plain JS, no dependencies. */
(function () {
  'use strict';

  var box = document.getElementById('prCalc');
  if (!box) return;

  var rate = parseFloat(box.getAttribute('data-rate')) || 0;
  var label = box.getAttribute('data-rate-label') || String(rate * 100);
  var pct = parseFloat(label) || rate * 100;
  var priceEl = document.getElementById('prPrice'), priceR = document.getElementById('prPriceR');
  var qtyR = document.getElementById('prQtyR'), qtyEl = document.getElementById('prQty'), freeEl = document.getElementById('prFree');

  function $(id) { return document.getElementById(id); }
  function n(x) { return Math.round(x).toLocaleString('en-US'); }
  function price() { return parseInt((priceEl.value || '0').replace(/\D/g, ''), 10) || 0; }
  function qty() { return Math.max(1, parseInt(qtyR.value, 10) || 1); }

  function update() {
    var p = price(), q = qty(), free = freeEl.checked;
    var gross = free ? 0 : p * q, com = free ? 0 : Math.round(gross * rate), bal = gross - com;
    $('prGross').textContent = 'UGX ' + n(gross);
    $('prCom').innerHTML = (com ? '&minus; ' : '') + 'UGX ' + n(com);
    $('prBal').textContent = 'UGX ' + n(bal);
    $('prGl').textContent = free ? 'Free tickets, no price' : (q > 1 ? 'Ticket sales (' + q + ' tickets)' : 'Ticket sales');
    $('prCl').textContent = free ? 'Commission (free tickets: 0%)' : 'Commission (' + label + '%)';
    var keep = free ? 100 : 100 - pct;
    $('prKeep').style.width = keep + '%';
    $('prTake').style.width = (100 - keep) + '%';
    $('prKeepL').textContent = keep + '% to you';
    $('prTakeL').textContent = (free ? '0' : label) + '% commission';
    qtyEl.textContent = q;
    priceEl.disabled = priceR.disabled = free;
    box.classList.toggle('is-free', free);
  }

  priceEl.addEventListener('input', function () {
    var d = priceEl.value.replace(/\D/g, '').slice(0, 9);
    priceEl.value = d ? parseInt(d, 10).toLocaleString('en-US') : '';
    priceR.value = Math.max(+priceR.min, Math.min(+priceR.max, parseInt(d || '0', 10)));
    update();
  });
  priceR.addEventListener('input', function () {
    priceEl.value = parseInt(priceR.value, 10).toLocaleString('en-US');
    update();
  });
  qtyR.addEventListener('input', update);
  freeEl.addEventListener('change', update);
  update();
})();
