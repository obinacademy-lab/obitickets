/* Sends a big video to /api/moment-chunk.php in pieces (11/18), so videos up to 1 GB get past the hosting
   server's per-request limit. A piece that fails (weak signal) is retried a few times before giving up.
   11/27 (faster uploads): a few pieces go up AT THE SAME TIME (the server says how many), which is much faster on
   mobile networks where each request waits on the network; and a video that is being made lighter in the browser can
   be uploaded WHILE it is made (obiChunkStream), instead of after.

   window.obiChunkUpload(file, { csrf, eventId, duration, onProgress(0..1), signal: { cancelled } })
     -> Promise resolving to the upload id to post as chunk_id, or rejecting with an Error (message is shown).
   window.obiChunkStream({ csrf, eventId, duration, type, onSent(bytes) })
     -> { push(blob), end() -> Promise(upload id), cancel() }   (pieces are sent as soon as there is enough to send) */
(function () {
  'use strict';
  var URL_ = '/api/moment-chunk.php';
  var DEFAULT_PIECE = 5 * 1024 * 1024, DEFAULT_PARALLEL = 3;

  function post(fields, onProgress) {
    return new Promise(function (resolve, reject) {
      var fd = new FormData();
      Object.keys(fields).forEach(function (k) { if (fields[k] != null) { fd.append(k, fields[k]); } });
      var xhr = new XMLHttpRequest();
      xhr.open('POST', URL_);
      xhr.setRequestHeader('Accept', 'application/json');
      if (onProgress) { xhr.upload.addEventListener('progress', function (e) { if (e.lengthComputable) { onProgress(e.loaded); } }); }
      xhr.onload = function () {
        var r = null; try { r = JSON.parse(xhr.responseText); } catch (e) { r = null; }
        if (xhr.status >= 200 && xhr.status < 300 && r && r.ok) { resolve(r); return; }
        var err = new Error((r && r.error) || 'The upload did not finish. Please try again.');
        err.status = xhr.status; err.data = r; reject(err);
      };
      xhr.onerror = function () { var err = new Error('We could not reach the server. Check your connection and try again.'); err.status = 0; reject(err); };
      xhr.send(fd);
    });
  }
  function wait(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }

  // one piece, retried up to 4 times on a weak signal (never on "not allowed")
  function sendPiece(csrf, id, offset, blob, onLoaded) {
    var tries = 0;
    function attempt() {
      return post({ csrf_token: csrf, action: 'part', id: id, offset: String(offset), chunk: blob }, onLoaded)
        .catch(function (err) {
          if (++tries <= 4 && err.status !== 403 && err.status !== 401) { if (onLoaded) { onLoaded(0); } return wait(1500 * tries).then(attempt); }
          throw err;
        });
    }
    return attempt();
  }

  // runs jobs (functions returning promises) with at most `limit` running at once; stops at the first failure
  function pool(limit, nextJob) {
    return new Promise(function (resolve, reject) {
      var running = 0, failed = false, finished = false;
      function pump() {
        if (failed || finished) { return; }
        var job;
        while (running < limit && (job = nextJob())) {
          running++;
          job().then(function () { running--; pump(); }, function (err) { if (!failed) { failed = true; reject(err); } });
        }
        if (running === 0 && !nextJob.more()) { finished = true; resolve(); }
      }
      nextJob.wake = pump;
      pump();
    });
  }

  window.obiChunkUpload = function (file, o) {
    o = o || {};
    var signal = o.signal || {};
    var id;
    return post({ csrf_token: o.csrf, action: 'start', event_id: o.eventId, size: file.size, type: file.type || 'video/mp4', duration: o.duration != null ? String(o.duration) : null })
      .then(function (r) {
        id = r.id;
        var size = r.chunk || DEFAULT_PIECE, par = Math.max(1, Math.min(6, r.parallel || DEFAULT_PARALLEL));
        var offsets = [];
        for (var at = 0; at < file.size; at += size) { offsets.push(at); }
        var done = 0, live = {}, i = 0;
        function report() {
          if (!o.onProgress) { return; }
          var sum = done; Object.keys(live).forEach(function (k) { sum += live[k]; });
          o.onProgress(Math.min(1, sum / file.size));
        }
        function nextJob() {
          if (signal.cancelled || i >= offsets.length) { return null; }
          var off = offsets[i++], end = Math.min(off + size, file.size);
          return function () {
            if (signal.cancelled) { return Promise.reject(new Error('Upload cancelled.')); }
            live[off] = 0;
            return sendPiece(o.csrf, id, off, file.slice(off, end, 'application/octet-stream'), function (loaded) { live[off] = loaded; report(); })
              .then(function () { delete live[off]; done += end - off; report(); });
          };
        }
        nextJob.more = function () { return !signal.cancelled && i < offsets.length; };
        return pool(par, nextJob);
      })
      .then(function () {
        if (signal.cancelled) { throw new Error('Upload cancelled.'); }
        if (o.onProgress) { o.onProgress(1); }
        return id;
      })
      .catch(function (err) {
        if (id && signal.cancelled) { post({ csrf_token: o.csrf, action: 'cancel', id: id }).catch(function () {}); }
        throw err;
      });
  };

  /* Upload while the file is still being made: push() the pieces as the browser produces them, end() when it is done. */
  window.obiChunkStream = function (o) {
    o = o || {};
    var id = null, size = DEFAULT_PIECE, par = DEFAULT_PARALLEL, failure = null, cancelled = false, ended = false;
    var buffer = [], bufBytes = 0, nextOffset = 0, total = 0, sent = 0;
    var queue = [], running = 0, waiters = [];
    var started = post({ csrf_token: o.csrf, action: 'start', stream: '1', size: '0', event_id: o.eventId, type: o.type || 'video/mp4', duration: o.duration != null ? String(o.duration) : null })
      .then(function (r) { id = r.id; size = r.chunk || DEFAULT_PIECE; par = Math.max(1, Math.min(6, r.parallel || DEFAULT_PARALLEL)); cut(false); pump(); },
            function (err) { failure = err; settle(); });
    function cut(final) {
      if (id === null) { return; }   // the size of a piece is known once the upload has started
      while (bufBytes >= size || (final && bufBytes > 0)) {
        var all = new Blob(buffer), n = Math.min(size, bufBytes);
        queue.push({ offset: nextOffset, blob: all.slice(0, n, 'application/octet-stream') });
        buffer = n < bufBytes ? [all.slice(n)] : []; bufBytes -= n; nextOffset += n;
      }
    }
    function settle() {
      if (failure || (ended && id !== null && bufBytes === 0 && !queue.length && running === 0)) {
        var w = waiters; waiters = []; w.forEach(function (fn) { fn(); });
      }
    }
    function pump() {
      while (!failure && !cancelled && id !== null && running < par && queue.length) {
        var p = queue.shift();
        running++;
        (function (p) {
          sendPiece(o.csrf, id, p.offset, p.blob, null).then(function () {
            running--; sent += p.blob.size; if (o.onSent) { o.onSent(sent); }
            pump(); settle();
          }, function (err) { running--; if (!failure) { failure = err; } settle(); });
        })(p);
      }
    }
    return {
      push: function (blob) {
        if (!blob || !blob.size || failure || cancelled) { return; }
        buffer.push(blob); bufBytes += blob.size; total += blob.size;
        cut(false); pump();
      },
      end: function () {
        ended = true;
        return started.then(function () {
          cut(true); pump();
          return new Promise(function (resolve) { waiters.push(resolve); settle(); });
        }).then(function () {
          if (failure) { throw failure; }
          if (cancelled) { throw new Error('Upload cancelled.'); }
          return post({ csrf_token: o.csrf, action: 'finish', id: id, size: String(total) });
        }).then(function () { return id; });
      },
      cancel: function () {
        cancelled = true;
        if (id) { post({ csrf_token: o.csrf, action: 'cancel', id: id }).catch(function () {}); }
      },
      failed: function () { return failure; },
      total: function () { return total; },
      sent: function () { return sent; }
    };
  };
})();
