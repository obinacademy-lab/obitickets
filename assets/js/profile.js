/* My profile: show the chosen picture before it is saved, and refuse obvious problems on the spot.
   The server checks everything again; this only saves a round trip. */
(function () {
  'use strict';
  var form = document.querySelector('[data-pf-photo]');
  if (!form) return;
  var input = form.querySelector('[data-pf-file]');
  var save = form.querySelector('[data-pf-save]');
  var label = form.querySelector('[data-pf-name]');
  var preview = form.querySelector('[data-pf-preview]');
  var MAX = 5 * 1024 * 1024;
  var original = preview.innerHTML;
  var url = null;

  function reset(msg) {
    if (url) { URL.revokeObjectURL(url); url = null; }
    preview.innerHTML = original;
    save.disabled = true;
    label.textContent = msg || 'No picture chosen';
    label.classList.toggle('is-bad', !!msg);
  }

  input.addEventListener('change', function () {
    var f = input.files && input.files[0];
    if (!f) { reset(); return; }
    if (!/^image\/(jpeg|png|webp|gif)$/.test(f.type)) { input.value = ''; reset('That is not a JPG, PNG, WEBP or GIF picture.'); return; }
    if (f.size > MAX) { input.value = ''; reset('That picture is over 5MB. Please choose a smaller one.'); return; }
    if (url) URL.revokeObjectURL(url);
    url = URL.createObjectURL(f);
    var img = document.createElement('img');
    img.className = 'pf-av pf-av-xl';
    img.alt = '';
    img.width = 112; img.height = 112;
    img.style.cssText = 'width:112px;height:112px;object-fit:cover';
    img.src = url;
    preview.innerHTML = '';
    preview.appendChild(img);
    label.textContent = f.name;
    label.classList.remove('is-bad');
    save.disabled = false;
  });
})();
