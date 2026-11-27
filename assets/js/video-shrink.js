/* Makes a video lighter in the browser before (and while) it uploads (11/27). Owner: a 422 MB video took over 30 minutes for 40%
   on a ~0.1 MB/s connection: the only real speed-up is sending fewer megabytes, so heavy videos up to the 10-minute limit are
   re-made at 720p (about 1.5-2 Mbit/s with the sound) while the pieces go up (chunk-upload.js obiChunkStream).
   Re-making runs at the video's own speed (a 5-minute video takes about 5 minutes), so it only happens when it saves a lot.

   window.obiLightenPlan(file, seconds) -> { shrink: bool, videoBits, audioBits, estimate (bytes) }
   window.obiShrinkVideo(url, { seconds, videoBits, audioBits, fit: 'cover916'|'keep', onProgress(0..1), onData(blob, mime) })
     -> { promise -> { blob, mime, duration }, cancel() }
   window.obiKeepAwake.hold() / .release(): keeps the phone screen on while a video is made or sent (a sleeping screen pauses it). */
(function () {
  'use strict';
  var canShrink = !!(window.MediaRecorder && window.HTMLCanvasElement && HTMLCanvasElement.prototype.captureStream);

  window.obiLightenPlan = function (file, seconds) {
    var s = Math.max(1, Number(seconds) || 0);
    var videoBits = s <= 120 ? 2000000 : 1400000, audioBits = 128000;
    var target = (videoBits + audioBits) / 8 * s;                 // bytes after re-making
    var sourceBitrate = file.size * 8 / s;
    // worth it when the file is heavy and at least ~1.8x bigger than what it would become
    var shrink = canShrink && file.size > 12 * 1024 * 1024 && sourceBitrate > (videoBits + audioBits) * 1.8;
    return { shrink: shrink, videoBits: videoBits, audioBits: audioBits, estimate: Math.round(target) };
  };

  /* ---- keep the screen on (Screen Wake Lock: Chrome/Android, Safari 16.4+; silently nothing elsewhere) ---- */
  var lock = null, holders = 0;
  function grab() {
    if (!holders || lock || !navigator.wakeLock || document.visibilityState !== 'visible') { return; }
    navigator.wakeLock.request('screen').then(function (l) { lock = l; l.addEventListener('release', function () { lock = null; }); }, function () { /* not allowed: carry on */ });
  }
  document.addEventListener('visibilitychange', grab);   // the browser drops the lock when the tab is hidden: take it back on return
  window.obiKeepAwake = {
    hold: function () { holders++; grab(); },
    release: function () { holders = Math.max(0, holders - 1); if (!holders && lock) { try { lock.release(); } catch (e) { /* ignore */ } lock = null; } }
  };

  window.obiShrinkVideo = function (url, o) {
    o = o || {};
    var seconds = o.seconds, onProgress = o.onProgress || function () {}, onData = o.onData || null;
    var cancelled = false, rec = null, v = null, ac = null, timer = null, holding = false;
    function letGo() { if (holding) { holding = false; window.obiKeepAwake.release(); } }
    var handle = { cancel: function () { cancelled = true; clearInterval(timer); try { if (rec && rec.state !== 'inactive') { rec.stop(); } } catch (e) { /* ignore */ } if (v) { v.pause(); } try { if (ac) { ac.close(); } } catch (e) { /* ignore */ } letGo(); } };
    handle.promise = new Promise(function (resolve, reject) {
      if (!canShrink) { reject(new Error("This browser can't make videos lighter.")); return; }
      window.obiKeepAwake.hold(); holding = true;
      var done = letGo;
      v = document.createElement('video'); v.playsInline = true; v.preload = 'auto'; v.src = url;
      v.addEventListener('error', function () { done(); reject(new Error("This video can't be read by your browser.")); });
      v.addEventListener('loadedmetadata', function () {
        var vw0 = v.videoWidth || 720, vh0 = v.videoHeight || 1280, W, H;
        if (o.fit === 'keep') {   // keep the video's own shape, long side at most 1280 (even numbers for the encoder)
          var sc0 = Math.min(1, 1280 / Math.max(vw0, vh0));
          W = Math.max(2, Math.round(vw0 * sc0 / 2) * 2); H = Math.max(2, Math.round(vh0 * sc0 / 2) * 2);
        } else { W = 720; H = 1280; }
        var cv = document.createElement('canvas'); cv.width = W; cv.height = H;
        var cx = cv.getContext('2d'), stream = cv.captureStream(30);
        try {
          var AC = window.AudioContext || window.webkitAudioContext;
          ac = new AC(); var srcNode = ac.createMediaElementSource(v), dest = ac.createMediaStreamDestination();
          srcNode.connect(dest);   // the sound goes into the recording, not the speakers
          dest.stream.getAudioTracks().forEach(function (t) { stream.addTrack(t); });
        } catch (e) { /* silent video if the browser refuses */ }
        var type = ['video/mp4;codecs=avc1.42E01F,mp4a.40.2', 'video/mp4;codecs=avc1,mp4a', 'video/mp4', 'video/webm;codecs=vp9,opus', 'video/webm;codecs=vp8,opus', 'video/webm;codecs=vp8', 'video/webm'].filter(function (m) { return MediaRecorder.isTypeSupported(m); })[0] || '';
        var chunks = [];
        try { rec = new MediaRecorder(stream, type ? { mimeType: type, videoBitsPerSecond: o.videoBits || 2000000, audioBitsPerSecond: o.audioBits || 128000 } : undefined); } catch (e) { done(); reject(new Error("This browser couldn't start recording.")); return; }
        rec.ondataavailable = function (e) { if (e.data && e.data.size) { chunks.push(e.data); if (onData && !cancelled) { onData(e.data, rec.mimeType || type || 'video/webm'); } } };
        rec.onstop = function () { done(); if (cancelled) { return; } clearInterval(timer); resolve({ blob: new Blob(chunks, { type: rec.mimeType || type || 'video/webm' }), mime: rec.mimeType || type || 'video/webm', duration: Math.min(seconds, v.duration || seconds) }); };
        var draw = function () {
          var vw = v.videoWidth, vh = v.videoHeight; if (!vw) { return; }
          if (o.fit === 'keep') { cx.drawImage(v, 0, 0, W, H); return; }
          var sc = Math.max(W / vw, H / vh), dw = vw * sc, dh = vh * sc;
          cx.drawImage(v, (W - dw) / 2, (H - dh) / 2, dw, dh);
        };
        var finish = function () { if (rec.state !== 'inactive') { v.pause(); setTimeout(function () { if (rec.state !== 'inactive') { rec.stop(); } }, 150); } };
        rec.start(500);
        v.currentTime = 0;
        var p = v.play(); if (p && p.catch) { p.catch(function () { done(); reject(new Error('Your browser would not play this video to make it lighter.')); }); }
        timer = setInterval(function () {
          if (cancelled) { return; }
          draw();
          onProgress(Math.min(1, v.currentTime / seconds));
          if (v.ended || v.currentTime >= seconds - 0.05) { clearInterval(timer); onProgress(1); finish(); }
        }, 33);
      });
    });
    return { promise: handle.promise, cancel: handle.cancel };
  };
})();
