/* Event form: guesses the District from the venue name / address while the organizer types, until they pick one
   themselves. Mirrors detect_district() in includes/places.php ("X District" first, then the longest place name). */
(function () {
  var sel = document.getElementById('district');
  if (!sel || sel.dataset.wired) return;
  sel.dataset.wired = '1';
  var map = {};
  try { map = JSON.parse(sel.getAttribute('data-places') || '{}'); } catch (e) { return; }
  var names = Object.keys(map).sort(function (a, b) { return b.length - a.length; });
  var touched = sel.value !== '';
  var fields = ['venue_address', 'venue_name'].map(function (id) { return document.getElementById(id); }).filter(Boolean);
  var esc = function (s) { return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); };
  var abroad = /\b(Kenya|Nairobi|Tanzania|Rwanda|Kigali|Ghana|Nigeria|Lagos|Accra|South Sudan|Juba|Burundi|Congo|DRC)\b/i;

  function guess() {
    var text = fields.map(function (f) { return f.value; }).join(' , ');
    if (!text.trim()) return '';
    if (abroad.test(text) && !/\bUganda\b/i.test(text)) return '';
    for (var o = 0; o < sel.options.length; o++) {
      var d = sel.options[o].value;
      if (d && new RegExp('\\b' + esc(d) + '\\s+District\\b', 'i').test(text)) return d;
    }
    for (var i = 0; i < names.length; i++) {
      if (new RegExp('\\b' + esc(names[i]) + '\\b', 'i').test(text)) return map[names[i]];
    }
    return '';
  }

  sel.addEventListener('change', function () { touched = sel.value !== ''; });
  fields.forEach(function (f) {
    f.addEventListener('input', function () {
      if (touched) return;
      var d = guess();
      sel.value = d;
    });
  });
})();
