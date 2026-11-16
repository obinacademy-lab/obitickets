/* Home (Marketplace Grid): the place pill in the top bar. Picking a place remembers it for the home page's
   "Popular in ..." list (cookie, 90 days) and opens Browse for that place; "All of Uganda" forgets it. */
(function () {
  var sel = document.getElementById('mgPlace');
  if (!sel) return;
  sel.addEventListener('change', function () {
    var v = sel.value;
    var secure = location.protocol === 'https:' ? '; Secure' : '';
    document.cookie = 'ot_place=' + encodeURIComponent(v) + '; Path=/; SameSite=Lax' + secure + (v ? '; Max-Age=7776000' : '; Max-Age=0');
    window.location.href = v ? '/search.php?where=' + encodeURIComponent(v) : '/search.php';
  });
})();
