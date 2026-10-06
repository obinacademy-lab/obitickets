/* Finds the tempo of an audio file in the browser: tempo (BPM), where the first beat falls, the length
   and a waveform to draw. Decoding and analysis happen on the visitor's device; only the numbers are
   sent to the server. Works on a File/Blob or an ArrayBuffer.

     BeatAnalyzer.analyze(file).then(function (r) { r.bpm, r.offset, r.duration, r.peaks })
*/
(function (g) {
  'use strict';

  function decode(buf) {
    var Ctx = g.AudioContext || g.webkitAudioContext;
    if (!Ctx) return Promise.reject(new Error('This browser cannot read audio files.'));
    var ctx = new Ctx();
    return new Promise(function (resolve, reject) {
      var done = function (b) { try { ctx.close(); } catch (e) { /* ignore */ } resolve(b); };
      var fail = function () { try { ctx.close(); } catch (e) { /* ignore */ } reject(new Error("That audio file couldn't be read.")); };
      try {
        var p = ctx.decodeAudioData(buf.slice(0), done, fail);
        if (p && p.catch) p.catch(fail);
      } catch (e) { fail(); }
    });
  }

  function toBuffer(src) {
    if (src instanceof ArrayBuffer) return Promise.resolve(src);
    if (src && src.arrayBuffer) return src.arrayBuffer();
    return Promise.reject(new Error('Unsupported input.'));
  }

  function analyzeBuffer(ab) {
    var sr = ab.sampleRate;
    var len = Math.min(ab.length, Math.floor(sr * 120)); // 2 minutes is plenty to find a steady tempo
    var mono = new Float32Array(len), c, i;
    for (c = 0; c < ab.numberOfChannels; c++) {
      var d = ab.getChannelData(c);
      for (i = 0; i < len; i++) mono[i] += d[i] / ab.numberOfChannels;
    }

    // waveform bars for the picker (over the whole track)
    var bars = 600, peaks = new Float32Array(bars), step = Math.max(1, Math.floor(ab.length / bars));
    var first = ab.getChannelData(0);
    for (i = 0; i < bars; i++) {
      var mx = 0, from = i * step, to = Math.min(ab.length, from + step);
      for (var k = from; k < to; k += 8) { var v = Math.abs(first[k]); if (v > mx) mx = v; }
      peaks[i] = mx;
    }

    // energy in ~10 ms frames, in a bass band and in the full band
    var hop = Math.max(1, Math.round(sr / 100)), fps = sr / hop, n = Math.floor(len / hop);
    var a = 1 - Math.exp(-2 * Math.PI * 150 / sr), y = 0;
    var low = new Float32Array(n), full = new Float32Array(n);
    for (var f = 0; f < n; f++) {
      var sl = 0, sf = 0, base = f * hop;
      for (var j = 0; j < hop; j++) {
        var x = mono[base + j];
        y += a * (x - y);
        sl += y * y; sf += x * x;
      }
      low[f] = Math.log(1 + 100 * Math.sqrt(sl / hop));
      full[f] = Math.log(1 + 100 * Math.sqrt(sf / hop));
    }
    // onset strength = rises in energy, with a local average taken away
    var onset = new Float32Array(n);
    for (f = 1; f < n; f++) onset[f] = Math.max(0, low[f] - low[f - 1]) + 0.6 * Math.max(0, full[f] - full[f - 1]);
    var w = Math.round(fps * 0.5), out = new Float32Array(n), run = 0;
    for (f = 0; f < n; f++) {
      run += onset[f]; if (f >= 2 * w + 1) run -= onset[f - 2 * w - 1];
      var cnt = Math.min(f + 1, 2 * w + 1);
      out[f] = Math.max(0, onset[f] - run / cnt);
    }

    // tempo: strongest repeat period between 60 and 190 BPM, nudged toward common dance tempos
    var minLag = Math.floor(fps * 60 / 190), maxLag = Math.ceil(fps * 60 / 60);
    var ac = new Float32Array(maxLag * 4 + 2);
    function r(lag) {
      if (ac[lag] || lag >= n - 1) return ac[lag] || 0;
      var s = 0;
      for (var q = 0; q + lag < n; q++) s += out[q] * out[q + lag];
      ac[lag] = s / (n - lag);
      return ac[lag];
    }
    var best = 0, bestLag = minLag, score = new Float32Array(maxLag + 2);
    for (var lag = minLag; lag <= maxLag; lag++) {
      var bpmHere = 60 * fps / lag;
      var prior = Math.exp(-0.5 * Math.pow(Math.log(bpmHere / 118) / Math.log(2) / 0.9, 2));
      var sc = (r(lag) + 0.5 * r(lag * 2) + 0.25 * r(lag * 4)) * (0.8 + 0.2 * prior);
      score[lag] = sc;
      if (sc > best) { best = sc; bestLag = lag; }
    }
    // refine between frames with a parabola through the peak
    var lagf = bestLag;
    if (bestLag > minLag && bestLag < maxLag) {
      var l = score[bestLag - 1], m = score[bestLag], rr = score[bestLag + 1], den = l - 2 * m + rr;
      if (den !== 0) lagf = bestLag + 0.5 * (l - rr) / den;
    }
    var bpm = 60 * fps / lagf;
    while (bpm < 75) bpm *= 2;
    while (bpm > 170) bpm /= 2;
    // Fine-tune: over a whole track a tiny tempo error drifts the grid, so test tempos around the estimate
    // and keep the one whose beat grid lands on the most onsets. This also yields the first-beat offset.
    function grid(lag) {
      var bp = 0, bs = -1;
      for (var ph = 0; ph < lag; ph += 0.25) {
        var sum = 0;
        for (var pos = ph; pos < n - 1; pos += lag) sum += out[Math.round(pos)];
        if (sum > bs) { bs = sum; bp = ph; }
      }
      return { sum: bs, phase: bp };
    }
    var bestPhase = 0, bestSum = -1, bestBpm = bpm;
    for (var cand = bpm - 1.5; cand <= bpm + 1.5; cand += 0.05) {
      var lagC = 60 * fps / cand, gr = grid(lagC);
      var norm = gr.sum * lagC; // fewer, longer beats at a lower tempo would otherwise score lower
      if (norm > bestSum) { bestSum = norm; bestPhase = gr.phase; bestBpm = cand; }
    }
    bpm = bestBpm;
    return { bpm: Math.round(bpm * 10) / 10, offset: Math.round(bestPhase / fps * 1000) / 1000, duration: ab.duration, peaks: peaks };
  }

  g.BeatAnalyzer = {
    analyze: function (src) {
      return toBuffer(src).then(decode).then(analyzeBuffer);
    }
  };
})(window);
