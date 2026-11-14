/* Organizer Socials dashboard (public/org-socials.php): the video table filters, and the "Upload Event Video"
   panel. The panel posts one video or photo to /api/moment-upload.php, the same endpoint the Upload and manage page
   uses, with the caption (hashtags added at the end), the event, the video length and a cover frame. */
(function () {
  'use strict';

  /* ---- table filters ---- */
  var tabs = document.querySelectorAll('.sp-tabs [data-f]');
  tabs.forEach(function (b) {
    b.addEventListener('click', function () {
      var f = b.getAttribute('data-f');
      tabs.forEach(function (x) { var on = x === b; x.classList.toggle('on', on); x.setAttribute('aria-selected', on ? 'true' : 'false'); });
      document.querySelectorAll('.sp-table tbody tr').forEach(function (tr) { tr.hidden = f !== 'all' && tr.getAttribute('data-status') !== f; });
    });
  });
  // one actions menu open at a time
  document.addEventListener('click', function (e) {
    document.querySelectorAll('.sp-more[open]').forEach(function (d) { if (!d.contains(e.target)) { d.open = false; } });
  });

  var box = document.getElementById('spUp'), form = document.getElementById('spForm');
  if (!box || !form) { return; }
  var $ = function (id) { return document.getElementById(id); };
  var fileIn = $('spFile'), drop = $('spDrop'), row = $('spFileRow'), th = $('spFileTh');
  var evSel = $('spEvent'), evTh = $('spEvTh'), cap = $('spCap'), capN = $('spCapN');
  var tagWrap = $('spTags'), tagIn = $('spTag'), prog = $('spProg'), bar = $('spBar'), progT = $('spProgT');
  var msg = $('spMsg'), pub = $('spPub');
  var maxBytes = parseInt(box.getAttribute('data-max'), 10) || 0;
  var capMax = parseInt(box.getAttribute('data-cap'), 10) || 300;
  var file = null, duration = null, poster = null, tags = [], busy = false;

  function say(text, ok) { msg.textContent = text || ''; msg.hidden = !text; msg.classList.toggle('ok', !!ok); }
  function mb(n) { return n >= 1048576 ? (n / 1048576).toFixed(n >= 10485760 ? 0 : 1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB'; }

  /* ---- event picture beside the picker ---- */
  function paintEvent() {
    var o = evSel.options[evSel.selectedIndex];
    var img = o ? o.getAttribute('data-img') : '';
    evTh.style.backgroundImage = img ? 'url("' + img.replace(/"/g, '') + '")' : '';
  }
  evSel.addEventListener('change', paintEvent);
  paintEvent();

  /* ---- caption counter (hashtags count too, they are added to the caption) ---- */
  function fullCaption() {
    var c = cap.value.trim();
    var t = tags.map(function (x) { return '#' + x; }).join(' ');
    return (c + (c && t ? ' ' : '') + t).slice(0, capMax);
  }
  function paintCount() { capN.textContent = fullCaption().length + '/' + capMax; }
  cap.addEventListener('input', paintCount);

  /* ---- hashtags ---- */
  function paintTags() {
    tagWrap.querySelectorAll('.sp-tag').forEach(function (n) { n.remove(); });
    tags.forEach(function (t, i) {
      var s = document.createElement('span'); s.className = 'sp-tag'; s.textContent = '#' + t;
      var x = document.createElement('button'); x.type = 'button'; x.setAttribute('aria-label', 'Remove #' + t); x.textContent = '×';
      x.addEventListener('click', function () { tags.splice(i, 1); paintTags(); });
      s.appendChild(x); tagWrap.insertBefore(s, tagIn);
    });
    paintCount();
  }
  function addTag() {
    var v = tagIn.value.replace(/[#,\s]+/g, ' ').trim();
    if (!v) { return; }
    v.split(' ').forEach(function (w) {
      w = w.replace(/[^\p{L}\p{N}_]/gu, '').slice(0, 30);
      if (w && tags.indexOf(w) === -1 && tags.length < 8) { tags.push(w); }
    });
    tagIn.value = ''; paintTags();
  }
  tagIn.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' || e.key === ',' || e.key === ' ') { e.preventDefault(); addTag(); }
    else if (e.key === 'Backspace' && !tagIn.value && tags.length) { tags.pop(); paintTags(); }
  });
  tagIn.addEventListener('blur', addTag);

  /* ---- choosing a file: click or drag and drop ---- */
  function clearFile() {
    file = null; duration = null; poster = null; fileIn.value = '';
    row.hidden = true; drop.hidden = false; th.innerHTML = ''; say('');
  }
  $('spFileX').addEventListener('click', clearFile);
  function useFile(f) {
    say('');
    if (!f) { return; }
    var isVideo = /^video\//.test(f.type), isImage = /^image\/(jpeg|png|webp)$/.test(f.type);
    if (!isVideo && !isImage) { say('Choose a video (MP4 or MOV) or a photo (JPG, PNG or WebP).'); return; }
    if (isVideo && maxBytes && f.size > maxBytes) {
      say('This video is ' + mb(f.size) + '. Videos can be up to ' + mb(maxBytes) + ' here. Use "Upload and manage" below: it makes big videos smaller before sending.');
      return;
    }
    file = f; duration = null; poster = null;
    $('spFileName').textContent = f.name; $('spFileSize').textContent = mb(f.size);
    th.innerHTML = ''; row.hidden = false; drop.hidden = true;
    var url = URL.createObjectURL(f);
    if (isImage) {
      var im = document.createElement('img'); im.src = url; im.alt = ''; th.appendChild(im); return;
    }
    var v = document.createElement('video'); v.muted = true; v.playsInline = true; v.preload = 'auto'; v.src = url;
    th.appendChild(v);
    v.addEventListener('loadedmetadata', function () {
      duration = isFinite(v.duration) ? v.duration : null;
      try { v.currentTime = Math.min(0.5, (v.duration || 1) / 2); } catch (e) { /* the picture stays on the first frame */ }
    });
    v.addEventListener('seeked', function () {
      try {
        var w = v.videoWidth, h = v.videoHeight; if (!w || !h) { return; }
        var s = Math.min(1, 720 / Math.max(w, h)), c = document.createElement('canvas');
        c.width = Math.round(w * s); c.height = Math.round(h * s);
        c.getContext('2d').drawImage(v, 0, 0, c.width, c.height);
        c.toBlob(function (b) { poster = b; }, 'image/jpeg', 0.82);
      } catch (e) { poster = null; }
    }, { once: true });
  }
  fileIn.addEventListener('change', function () { useFile(fileIn.files && fileIn.files[0]); });
  ['dragenter', 'dragover'].forEach(function (t) { drop.addEventListener(t, function (e) { e.preventDefault(); drop.classList.add('is-over'); }); });
  ['dragleave', 'drop'].forEach(function (t) { drop.addEventListener(t, function (e) { e.preventDefault(); drop.classList.remove('is-over'); }); });
  drop.addEventListener('drop', function (e) { var f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0]; useFile(f); });

  /* ---- the Upload Video button in the table jumps here and opens the picker ---- */
  var go = document.getElementById('spUpGo');
  if (go) { go.addEventListener('click', function (e) { e.preventDefault(); box.scrollIntoView({ behavior: 'smooth', block: 'start' }); if (!file) { setTimeout(function () { fileIn.click(); }, 350); } }); }

  /* ---- publish ---- */
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    if (busy) { return; }
    addTag();
    if (!file) { say('Choose a video or photo first.'); return; }
    if (!cap.value.trim()) { say('Write a caption so people know what they are watching.'); cap.focus(); return; }
    var fd = new FormData();
    fd.append('csrf_token', box.getAttribute('data-csrf'));
    fd.append('event_id', evSel.value);
    fd.append('caption', fullCaption());
    fd.append('file', file, file.name);
    if (duration != null) { fd.append('duration', String(Math.round(duration))); }
    if (poster) { fd.append('poster', poster, 'cover.jpg'); }
    busy = true; pub.disabled = true; say(''); prog.hidden = false; bar.style.width = '0%'; progT.textContent = 'Uploading… 0%';
    var xhr = new XMLHttpRequest();
    xhr.open('POST', box.getAttribute('data-upload'));
    xhr.upload.addEventListener('progress', function (ev) {
      if (!ev.lengthComputable) { return; }
      var p = Math.round(ev.loaded / ev.total * 100);
      bar.style.width = p + '%'; progT.textContent = p < 100 ? 'Uploading… ' + p + '%' : 'Finishing…';
    });
    xhr.onload = function () {
      var r = null; try { r = JSON.parse(xhr.responseText); } catch (err) { r = null; }
      if (xhr.status >= 200 && xhr.status < 300 && r && r.ok) {
        bar.style.width = '100%'; progT.textContent = 'Posted';
        say('Your video is live on Socials.', true);
        setTimeout(function () { window.location.reload(); }, 900);
        return;
      }
      busy = false; pub.disabled = false; prog.hidden = true;
      say((r && r.error) || 'The upload did not finish. Please try again.');
    };
    xhr.onerror = function () { busy = false; pub.disabled = false; prog.hidden = true; say('We could not reach the server. Check your connection and try again.'); };
    xhr.send(fd);
  });
})();
