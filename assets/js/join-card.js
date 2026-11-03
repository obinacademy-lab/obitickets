/* Keeps a Join card honest while the page stays open: when the room opens (30 minutes before the start) the
   disabled button turns into a real Join link, and after the event ends it goes away. No meeting link is ever in
   the page; the button only points at /join.php, which checks everything again on the server. */
(function () {
  'use strict';
  function tick() {
    var now = Math.floor(Date.now() / 1000);
    Array.prototype.forEach.call(document.querySelectorAll('.jn-card[data-jn-opens]'), function (card) {
      var opens = parseInt(card.getAttribute('data-jn-opens'), 10);
      var closes = parseInt(card.getAttribute('data-jn-closes'), 10);
      var id = card.getAttribute('data-jn-event');
      var status = card.querySelector('[data-jn-status]');
      var slot = card.querySelector('[data-jn-btn]');
      if (!status || !slot) return;
      if (now >= opens && now <= closes && !slot.querySelector('.jn-go')) {
        slot.innerHTML = '<a class="jn-btn jn-go" href="/join.php?event=' + encodeURIComponent(id) + '" rel="noopener">Join now</a>';
        status.textContent = 'Open now. Tap to join.';
      } else if (now > closes && slot.innerHTML !== '') {
        slot.innerHTML = '';
        status.textContent = 'This event has ended.';
      }
    });
  }
  tick();
  setInterval(tick, 20000);
})();
