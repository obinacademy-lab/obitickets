/* Sends a big video to /api/moment-chunk.php in pieces (11/18), so videos up to 1 GB get past the hosting
   server's per-request limit. A piece that fails (weak signal) is retried a few times before giving up.
   window.obiChunkUpload(file, { csrf, eventId, duration, onProgress(0..1), signal: { cancelled } })
     -> Promise resolving to the upload id to post as chunk_id, or rejecting with an Error (message is shown). */
(function () {
  'use strict';
  var URL_ = '/api/moment-chunk.php';

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

  window.obiChunkUpload = function (file, o) {
    o = o || {};
    var signal = o.signal || {};
    var id, size = 0;
    return post({ csrf_token: o.csrf, action: 'start', event_id: o.eventId, size: file.size, type: file.type || 'video/mp4', duration: o.duration != null ? String(o.duration) : null })
      .then(function (r) {
        id = r.id; size = r.chunk || 5 * 1024 * 1024;
        var offset = 0;
        function next() {
          if (signal.cancelled) { post({ csrf_token: o.csrf, action: 'cancel', id: id }).catch(function () {}); throw new Error('Upload cancelled.'); }
          if (offset >= file.size) { return id; }
          var end = Math.min(offset + size, file.size);
          var tries = 0;
          function attempt() {
            return post({ csrf_token: o.csrf, action: 'part', id: id, offset: String(offset), chunk: file.slice(offset, end, 'application/octet-stream') },
              function (loaded) { if (o.onProgress) { o.onProgress(Math.min(1, (offset + loaded) / file.size)); } })
              .catch(function (err) {
                // the server already has more than we thought (a retry after a dropped reply): carry on from there
                if (err.data && typeof err.data.received === 'number' && err.data.received > offset) { return err.data; }
                if (++tries <= 4 && err.status !== 403 && err.status !== 401) { return wait(1500 * tries).then(attempt); }
                throw err;
              });
          }
          return attempt().then(function (res) {
            offset = typeof res.received === 'number' ? res.received : end;
            if (o.onProgress) { o.onProgress(offset / file.size); }
            return next();
          });
        }
        return next();
      });
  };
})();
