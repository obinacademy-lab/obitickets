/* OBI GIFTS artwork (ADR-004): the 36 launch gifts drawn in code as shaded, dimensional SVG. Original drawings, nothing licensed,
   nothing downloaded, sharp at any size. A picture uploaded in Admin always wins over these; a gift with neither shows its emoji.
   Every drawing is a fixed string written here (never user text), so it is safe to put in with innerHTML.
   Public API: window.ObGiftArt.has(slug), ObGiftArt.svg(slug) -> '<svg ...>' (each call gets its own gradient ids). */
(function () {
  'use strict';
  var seq = 0;
  // materials: light, mid, dark
  var M = {
    gold: ['#FFF4B8', '#F6B91E', '#9A5B05'], red: ['#FF9DA6', '#F0313F', '#7E0C18'], pink: ['#FFD6E6', '#F472B6', '#9D1C5B'],
    blue: ['#CDF2FF', '#38BDF8', '#0A4F8A'], purple: ['#EBD9FF', '#9B5CF6', '#3B1585'], green: ['#CFF8DD', '#22C55E', '#0B5D2A'],
    silver: ['#FFFFFF', '#CDD5E1', '#69758A'], dark: ['#74748A', '#2B2B36', '#0C0C12'], orange: ['#FFE2AE', '#FB8C1A', '#9E3A05'],
    cream: ['#FFFFFF', '#F7EAD0', '#B89D6C'], brown: ['#E4B27C', '#9A5A2A', '#4A2410'], skin: ['#FFF0C2', '#F9C440', '#B7791F']
  };
  function defsFor(body) {
    var out = '', seen = {};
    body.replace(/§([rlh])-([a-z]+)/g, function (m, k, mat) {
      if (seen[m] || !M[mat]) { return m; }
      seen[m] = 1;
      var c = M[mat];
      if (k === 'r') { out += '<radialGradient id="§r-' + mat + '" cx="36%" cy="30%" r="78%"><stop offset="0" stop-color="' + c[0] + '"/><stop offset=".5" stop-color="' + c[1] + '"/><stop offset="1" stop-color="' + c[2] + '"/></radialGradient>'; }
      if (k === 'l') { out += '<linearGradient id="§l-' + mat + '" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="' + c[0] + '"/><stop offset=".55" stop-color="' + c[1] + '"/><stop offset="1" stop-color="' + c[2] + '"/></linearGradient>'; }
      if (k === 'h') { out += '<linearGradient id="§h-' + mat + '" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="' + c[2] + '"/><stop offset=".3" stop-color="' + c[0] + '"/><stop offset=".62" stop-color="' + c[1] + '"/><stop offset="1" stop-color="' + c[2] + '"/></linearGradient>'; }
      return m;
    });
    return out;
  }
  var FL = '<linearGradient id="§fl" x1="0" y1="1" x2="0" y2="0"><stop offset="0" stop-color="#E2370C"/><stop offset=".45" stop-color="#FFB21A"/><stop offset="1" stop-color="#FFF7B0"/></linearGradient>';
  function shadow(cx, rx, cy) { return '<ellipse cx="' + cx + '" cy="' + (cy || 112) + '" rx="' + rx + '" ry="' + Math.max(3, rx / 7) + '" fill="#000" opacity=".22"/>'; }
  function shine(cx, cy, rx, ry, op, rot) { return '<ellipse cx="' + cx + '" cy="' + cy + '" rx="' + rx + '" ry="' + ry + '" fill="#fff" opacity="' + (op || 0.5) + '"' + (rot ? ' transform="rotate(' + rot + ' ' + cx + ' ' + cy + ')"' : '') + '/>'; }
  function starPath(cx, cy, R, r, pts) {
    var d = '', n = pts || 5, i, a, rad;
    for (i = 0; i < n * 2; i++) { rad = i % 2 ? r : R; a = -Math.PI / 2 + i * Math.PI / n; d += (i ? 'L' : 'M') + (cx + rad * Math.cos(a)).toFixed(1) + ' ' + (cy + rad * Math.sin(a)).toFixed(1); }
    return d + 'Z';
  }
  function twinkle(cx, cy, s, col) { return '<path d="M' + cx + ' ' + (cy - s) + 'Q' + cx + ' ' + cy + ' ' + (cx + s) + ' ' + cy + 'Q' + cx + ' ' + cy + ' ' + cx + ' ' + (cy + s) + 'Q' + cx + ' ' + cy + ' ' + (cx - s) + ' ' + cy + 'Q' + cx + ' ' + cy + ' ' + cx + ' ' + (cy - s) + 'Z" fill="' + (col || '#FFF4B8') + '"/>'; }
  function burst(cx, cy, r, col, n) {
    var s = '<circle cx="' + cx + '" cy="' + cy + '" r="' + (r * 0.32) + '" fill="' + col + '" opacity=".35"/>', i, a, x1, y1, x2, y2;
    for (i = 0; i < n; i++) {
      a = i * 2 * Math.PI / n; x1 = cx + r * 0.28 * Math.cos(a); y1 = cy + r * 0.28 * Math.sin(a); x2 = cx + r * Math.cos(a); y2 = cy + r * Math.sin(a);
      s += '<path d="M' + x1.toFixed(1) + ' ' + y1.toFixed(1) + 'L' + x2.toFixed(1) + ' ' + y2.toFixed(1) + '" stroke="' + col + '" stroke-width="2.6" stroke-linecap="round"/><circle cx="' + x2.toFixed(1) + '" cy="' + y2.toFixed(1) + '" r="2.4" fill="#fff"/>';
    }
    return s;
  }
  function rays(cx, cy, r1, r2, n, col, w, op) {
    var s = '', i, a;
    for (i = 0; i < n; i++) { a = i * 2 * Math.PI / n; s += '<path d="M' + (cx + r1 * Math.cos(a)).toFixed(1) + ' ' + (cy + r1 * Math.sin(a)).toFixed(1) + 'L' + (cx + r2 * Math.cos(a)).toFixed(1) + ' ' + (cy + r2 * Math.sin(a)).toFixed(1) + '"/>'; }
    return '<g stroke="' + col + '" stroke-width="' + w + '" stroke-linecap="round" opacity="' + (op || 1) + '">' + s + '</g>';
  }
  function rose(cx, cy, r, mat) {
    return '<circle cx="' + cx + '" cy="' + cy + '" r="' + r + '" fill="url(#§r-' + mat + ')"/>' +
      '<path d="M' + (cx - r * 0.1) + ' ' + (cy - r * 0.55) + 'C' + (cx + r * 0.6) + ' ' + (cy - r * 0.6) + ' ' + (cx + r * 0.6) + ' ' + (cy + r * 0.25) + ' ' + cx + ' ' + (cy + r * 0.3) + 'C' + (cx - r * 0.45) + ' ' + (cy + r * 0.3) + ' ' + (cx - r * 0.4) + ' ' + (cy - r * 0.2) + ' ' + (cx + r * 0.05) + ' ' + (cy - r * 0.15) + '" stroke="' + M[mat][2] + '" stroke-width="' + (r * 0.16).toFixed(1) + '" fill="none" stroke-linecap="round" opacity=".75"/>' +
      shine(cx - r * 0.35, cy - r * 0.45, r * 0.28, r * 0.16, 0.55, -30);
  }
  function pent(cx, cy, r) { return starPath(cx, cy, r, r * Math.cos(Math.PI / 5), 5); }

  var ART = {
    'thumbs-up': function () {
      return shadow(58, 34) + '<rect x="16" y="60" width="24" height="44" rx="7" fill="url(#§l-red)"/><rect x="19" y="62" width="6" height="40" rx="3" fill="#fff" opacity=".3"/>' +
        '<rect x="34" y="54" width="58" height="52" rx="17" fill="url(#§r-skin)"/>' +
        '<path d="M42 60C40 46 44 30 51 19C56 11 68 12 68 23C68 34 63 42 66 56Z" fill="url(#§r-skin)"/>' +
        '<path d="M92 68H72M92 80H72M90 92H72" stroke="#B7791F" stroke-width="3" stroke-linecap="round" opacity=".55"/>' + shine(53, 30, 4, 9, 0.6, -18) + shine(52, 64, 10, 5, 0.4);
    },
    'applause': function () {
      return shadow(60, 34) + '<path d="M58 16V6M44 20l-6-7M76 20l6-7" stroke="#FFE08A" stroke-width="4" stroke-linecap="round"/>' +
        '<g transform="rotate(-18 50 84)"><rect x="34" y="28" width="30" height="56" rx="15" fill="url(#§r-skin)"/><path d="M41 36v16M49 33v18M57 36v16" stroke="#B7791F" stroke-width="2.2" stroke-linecap="round" opacity=".45"/><ellipse cx="66" cy="66" rx="7" ry="13" transform="rotate(28 66 66)" fill="url(#§r-skin)"/>' + shine(42, 42, 3, 8, 0.55) + '</g>' +
        '<g transform="rotate(18 70 84)"><rect x="56" y="28" width="30" height="56" rx="15" fill="url(#§r-skin)"/><path d="M63 36v16M71 33v18M79 36v16" stroke="#B7791F" stroke-width="2.2" stroke-linecap="round" opacity=".45"/><ellipse cx="54" cy="66" rx="7" ry="13" transform="rotate(-28 54 66)" fill="url(#§r-skin)"/>' + shine(64, 42, 3, 8, 0.55) + '</g>' +
        '<rect x="30" y="88" width="26" height="18" rx="5" fill="url(#§l-pink)" transform="rotate(-18 43 97)"/><rect x="64" y="88" width="26" height="18" rx="5" fill="url(#§l-pink)" transform="rotate(18 77 97)"/>';
    },
    'golden-star': function () {
      return shadow(60, 30) + '<path d="' + starPath(60, 60, 48, 21) + '" fill="url(#§r-gold)" stroke="#E0A10C" stroke-width="5" stroke-linejoin="round"/>' +
        '<path d="' + starPath(60, 60, 30, 13) + '" fill="#FFF4B8" opacity=".55"/>' + shine(46, 40, 8, 4, 0.7, -30) + twinkle(100, 22, 7) + twinkle(18, 30, 5);
    },
    'heart': function () {
      return shadow(60, 30) + '<path d="M60 104C20 78 8 54 20 36C32 18 54 22 60 40C66 22 88 18 100 36C112 54 100 78 60 104Z" fill="url(#§r-red)"/>' +
        '<path d="M60 96C30 76 22 58 30 46" stroke="#7E0C18" stroke-width="3" fill="none" opacity=".25" stroke-linecap="round"/>' + shine(36, 40, 9, 5, 0.65, -35) + shine(84, 38, 4, 2.5, 0.45, 30);
    },
    'fire': function () {
      return '<defs>' + FL + '</defs>' + shadow(60, 30, 113) +
        '<path d="M60 110C32 110 20 92 24 70C27 55 37 48 39 33C48 42 50 50 50 57C55 42 62 27 59 9C77 22 96 44 98 70C100 94 86 110 60 110Z" fill="url(#§fl)"/>' +
        '<path d="M60 106C45 106 38 95 41 84C43 74 52 69 54 58C61 67 63 74 63 79C68 72 71 65 71 56C80 65 82 76 82 85C82 98 73 106 60 106Z" fill="#FFD84A"/>' +
        '<ellipse cx="61" cy="94" rx="11" ry="12" fill="#FFF7D0"/>' + shine(46, 70, 3, 9, 0.35, 20);
    },
    'rose': function () {
      return shadow(60, 22) + '<path d="M60 72C57 86 63 98 60 112" stroke="#16A34A" stroke-width="5" fill="none" stroke-linecap="round"/>' +
        '<path d="M61 94C70 84 84 85 89 91C81 100 69 100 61 94Z" fill="url(#§r-green)"/><path d="M59 86C51 78 39 79 35 85C43 93 53 92 59 86Z" fill="url(#§r-green)"/>' +
        '<path d="M32 46C28 25 49 12 60 21C71 12 92 25 88 46C86 66 73 76 60 76C47 76 34 66 32 46Z" fill="url(#§r-red)"/>' +
        '<path d="M37 50C43 68 77 68 83 50C81 71 71 78 60 78C49 78 39 71 37 50Z" fill="#9E1024" opacity=".55"/>' +
        '<path d="M60 29C71 29 75 40 67 47C59 53 50 45 56 38C60 34 66 38 62 43" stroke="#7E0C18" stroke-width="3" fill="none" stroke-linecap="round"/>' + shine(43, 33, 6, 3.5, 0.6, -35);
    },
    'balloons': function () {
      return '<path d="M38 72C43 88 54 98 60 114M62 64C59 84 63 98 60 114M84 76C78 90 66 100 60 114" stroke="#E5E7EB" stroke-width="1.6" fill="none"/>' +
        '<ellipse cx="62" cy="36" rx="21" ry="26" fill="url(#§r-gold)"/><path d="M59 66h6l-3-5z" fill="#9A5B05"/>' + shine(54, 24, 5, 8, 0.6, -20) +
        '<ellipse cx="38" cy="47" rx="20" ry="25" fill="url(#§r-red)"/><path d="M35 76h6l-3-5z" fill="#7E0C18"/>' + shine(31, 36, 4.5, 7.5, 0.6, -20) +
        '<ellipse cx="85" cy="51" rx="19" ry="24" fill="url(#§r-blue)"/><path d="M82 79h6l-3-5z" fill="#0A4F8A"/>' + shine(78, 40, 4.5, 7.5, 0.6, -20);
    },
    'cupcake': function () {
      return shadow(60, 28) + '<path d="M32 66H88L80 108H40Z" fill="url(#§l-pink)"/><path d="M44 66L48 108M55 66L56.5 108M65 66L63.5 108M76 66L72 108" stroke="#fff" stroke-width="2.5" opacity=".45"/>' +
        '<path d="M26 66C24 52 38 45 46 47C48 34 70 32 74 45C86 43 97 55 94 66Z" fill="url(#§r-cream)"/><path d="M42 47C42 34 55 26 62 30C70 24 81 34 77 45Z" fill="url(#§r-cream)"/>' +
        '<path d="M38 58l5-2M52 52l3 4M70 55l5-1M82 60l3 3M60 40l4-2M48 64l4 1" stroke-width="3" stroke-linecap="round" stroke="#38BDF8"/><path d="M44 52l4 2M64 48l-2 4M76 52l4-3M58 62l4-1" stroke-width="3" stroke-linecap="round" stroke="#F0313F"/>' +
        '<circle cx="62" cy="23" r="8" fill="url(#§r-red)"/><path d="M63 16C64 9 70 5 75 5" stroke="#16A34A" stroke-width="2.2" fill="none" stroke-linecap="round"/>' + shine(59, 20, 2.5, 1.6, 0.8);
    },
    'rose-bouquet': function () {
      return shadow(60, 26) + '<path d="M30 40C24 30 30 22 38 26M90 40C96 30 90 22 82 26M44 30C40 18 50 12 56 20M76 30C80 18 70 12 64 20" stroke="#16A34A" stroke-width="7" stroke-linecap="round" fill="none"/>' +
        '<path d="M28 54H92L67 112H53Z" fill="url(#§l-cream)"/><path d="M28 54L60 112M92 54L60 112" stroke="#B89D6C" stroke-width="1.5" opacity=".5"/>' +
        rose(40, 46, 13, 'red') + rose(80, 46, 13, 'pink') + rose(60, 36, 14, 'red') + rose(50, 58, 11, 'pink') + rose(70, 58, 11, 'red') +
        '<path d="M60 84C48 72 36 76 42 86C46 92 56 88 60 84ZM60 84C72 72 84 76 78 86C74 92 64 88 60 84Z" fill="url(#§r-red)"/><path d="M57 86L50 102M63 86L70 102" stroke="#C81E2E" stroke-width="4" stroke-linecap="round"/><circle cx="60" cy="85" r="4.5" fill="#9E1024"/>';
    },
    'luxury-gift-box': function () {
      return shadow(62, 40, 110) + '<rect x="26" y="56" width="52" height="50" rx="3" fill="url(#§l-red)"/><path d="M78 56L96 47V96L78 106Z" fill="#991327"/>' +
        '<rect x="47" y="56" width="11" height="50" fill="url(#§h-gold)"/><path d="M84 53L91 49.5V99L84 102.5Z" fill="url(#§l-gold)"/>' +
        '<rect x="22" y="45" width="60" height="14" rx="3" fill="url(#§l-red)"/><path d="M22 45L40 35H100L82 45Z" fill="#FF5A68"/><path d="M82 45L100 35V49L82 59Z" fill="#B3192A"/>' +
        '<rect x="46" y="45" width="13" height="14" fill="url(#§h-gold)"/><path d="M46 45L64 35H77L59 45Z" fill="#FFE27A"/><path d="M33 39L39 36H99L93 39Z" fill="#F6B91E" opacity=".9"/>' +
        '<path d="M62 36C50 18 32 22 38 33C42 39 55 38 62 36Z" fill="url(#§r-gold)"/><path d="M62 36C74 18 92 22 86 33C82 39 69 38 62 36Z" fill="url(#§r-gold)"/><circle cx="62" cy="36" r="5.5" fill="#C98A0C"/>' +
        shine(34, 64, 3, 12, 0.3) + twinkle(102, 20, 6) + twinkle(20, 30, 4);
    },
    'music-notes': function () {
      return shadow(62, 30) + '<path d="M46 30L91 19V31L46 42Z" fill="url(#§l-gold)"/><rect x="45.5" y="30" width="5" height="57" rx="2" fill="url(#§h-gold)"/><rect x="86" y="19" width="5" height="57" rx="2" fill="url(#§h-gold)"/>' +
        '<ellipse cx="38" cy="87" rx="12" ry="8.5" transform="rotate(-22 38 87)" fill="url(#§r-gold)"/><ellipse cx="78" cy="77" rx="12" ry="8.5" transform="rotate(-22 78 77)" fill="url(#§r-gold)"/>' +
        shine(33, 84, 4, 2, 0.7, -22) + shine(73, 74, 4, 2, 0.7, -22) +
        '<rect x="27" y="14" width="3.6" height="34" rx="1.5" fill="url(#§h-blue)"/><path d="M30 14C38 18 40 24 36 32C40 24 34 22 30 22Z" fill="#38BDF8"/><ellipse cx="22" cy="48" rx="8" ry="5.6" transform="rotate(-22 22 48)" fill="url(#§r-blue)"/>';
    },
    'diamond': function () {
      return shadow(60, 30, 113) + '<path d="M16 46H104L60 108Z" fill="url(#§l-blue)"/><path d="M16 46L35 24H85L104 46Z" fill="url(#§r-blue)"/>' +
        '<path d="M44 24H76L70 46H50Z" fill="#E6F9FF" opacity=".85"/><path d="M35 24H44L50 46H16Z" fill="#7DD3FC" opacity=".75"/><path d="M76 24H85L104 46H70Z" fill="#0EA5E9" opacity=".75"/>' +
        '<path d="M50 46L60 108L16 46Z" fill="#BAE6FD" opacity=".45"/><path d="M70 46L60 108L104 46Z" fill="#06406E" opacity=".4"/><path d="M50 46H70L60 108Z" fill="#E6F9FF" opacity=".35"/>' +
        '<path d="M16 46H104M35 24L50 46L60 24M85 24L70 46L60 24" stroke="#fff" stroke-width="1.2" opacity=".55" fill="none"/>' + twinkle(98, 20, 8, '#fff') + twinkle(22, 24, 5, '#fff') + twinkle(84, 84, 4, '#fff');
    },
    'golden-microphone': function () {
      return shadow(62, 22) + '<defs><clipPath id="§mc"><circle cx="60" cy="36" r="21"/></clipPath></defs><g transform="rotate(-24 60 64)">' +
        '<path d="M51 62H69L65 112H55Z" fill="url(#§h-dark)"/><rect x="56" y="96" width="8" height="5" fill="#F6B91E"/><rect x="47" y="54" width="26" height="11" rx="3" fill="url(#§h-gold)"/>' +
        '<circle cx="60" cy="36" r="23" fill="url(#§r-gold)"/><path d="M38 28H82M37 36H83M38 44H82M50 14V58M60 12V60M70 14V58" stroke="#9A5B05" stroke-width="1.6" opacity=".45" clip-path="url(#§mc)"/>' +
        shine(51, 26, 8, 5, 0.6, -30) + '</g>' + twinkle(98, 24, 6) + '<path d="M96 52c4 4 4 10 0 14M104 46c7 7 7 19 0 26" stroke="#FFE08A" stroke-width="3" fill="none" stroke-linecap="round" opacity=".8"/>';
    },
    'party-popper': function () {
      return shadow(46, 26) + '<defs><clipPath id="§cn"><path d="M20 106L43 52L74 83Z"/></clipPath></defs><path d="M20 106L43 52L74 83Z" fill="url(#§l-gold)"/>' +
        '<g clip-path="url(#§cn)" stroke="#F0313F" stroke-width="6"><path d="M18 88L60 108M24 72L72 96M30 58L80 84"/></g><ellipse cx="58.5" cy="67.5" rx="22" ry="8" transform="rotate(45 58.5 67.5)" fill="#7A3E04"/>' +
        '<path d="M62 60C70 40 58 30 76 20" stroke="#38BDF8" stroke-width="4" fill="none" stroke-linecap="round"/><path d="M67 65C86 57 86 40 106 38" stroke="#F472B6" stroke-width="4" fill="none" stroke-linecap="round"/><path d="M58 57C56 40 43 33 48 16" stroke="#34D399" stroke-width="4" fill="none" stroke-linecap="round"/>' +
        '<rect x="86" y="18" width="7" height="4" rx="1" fill="#F6B91E" transform="rotate(30 89 20)"/><rect x="96" y="58" width="7" height="4" rx="1" fill="#F0313F" transform="rotate(-20 99 60)"/><rect x="34" y="20" width="7" height="4" rx="1" fill="#9B5CF6" transform="rotate(50 37 22)"/>' +
        '<circle cx="82" cy="34" r="3" fill="#FBBF24"/><circle cx="102" cy="24" r="2.5" fill="#38BDF8"/><circle cx="70" cy="10" r="2.5" fill="#F472B6"/>' + twinkle(100, 78, 5) + twinkle(40, 8, 4);
    },
    'gold-medal': function () {
      return shadow(60, 26) + '<path d="M36 6H56L70 60L58 64Z" fill="url(#§l-blue)"/><path d="M84 6H64L50 60L62 64Z" fill="url(#§l-red)"/>' +
        '<rect x="51" y="50" width="18" height="7" rx="3.5" fill="url(#§l-gold)"/><circle cx="60" cy="80" r="30" fill="url(#§r-gold)"/><circle cx="60" cy="80" r="22" fill="none" stroke="#9A5B05" stroke-width="2.5" opacity=".55"/>' +
        '<path d="' + starPath(60, 81, 13, 5.6) + '" fill="#FFF4B8" opacity=".95"/>' + shine(46, 66, 7, 4, 0.6, -35);
    },
    'crown': function () {
      return shadow(60, 40) + '<path d="M20 90L14 38L40 61L60 24L80 61L106 38L100 90Z" fill="url(#§r-gold)" stroke="#E0A10C" stroke-width="2" stroke-linejoin="round"/>' +
        '<path d="M60 44L68 56L60 68L52 56Z" fill="url(#§r-red)"/><rect x="17" y="84" width="86" height="19" rx="5" fill="url(#§l-gold)"/>' +
        '<circle cx="38" cy="93.5" r="5" fill="url(#§r-blue)"/><ellipse cx="60" cy="93.5" rx="7" ry="6" fill="url(#§r-red)"/><circle cx="82" cy="93.5" r="5" fill="url(#§r-green)"/>' +
        '<circle cx="14" cy="36" r="6" fill="url(#§r-silver)"/><circle cx="60" cy="21" r="7" fill="url(#§r-silver)"/><circle cx="106" cy="36" r="6" fill="url(#§r-silver)"/>' + shine(34, 62, 4, 10, 0.4, -20) + twinkle(96, 14, 6);
    },
    'golden-trophy': function () {
      return '<path d="M32 26C13 26 13 55 36 59M88 26C107 26 107 55 84 59" stroke="url(#§l-gold)" stroke-width="7" fill="none"/>' +
        '<path d="M31 17H89V40C89 62 77 75 60 75C43 75 31 62 31 40Z" fill="url(#§r-gold)"/><ellipse cx="60" cy="17" rx="29" ry="5" fill="#FFE98F"/>' +
        '<path d="' + starPath(60, 42, 11, 4.6) + '" fill="#FFF4B8" opacity=".85"/><path d="M54 75H66L64 89H56Z" fill="url(#§h-gold)"/><rect x="49" y="87" width="22" height="6" rx="2" fill="url(#§l-gold)"/>' +
        '<path d="M36 96H84L88 112H32Z" fill="url(#§l-dark)"/><rect x="41" y="92" width="38" height="6" rx="2" fill="url(#§l-gold)"/><rect x="50" y="101" width="20" height="6" rx="1" fill="#F6B91E"/>' + shine(42, 34, 4, 11, 0.5, -10);
    },
    'fireworks': function () {
      return '<path d="M44 114C44 92 44 74 42 54M80 114C80 96 80 74 81 44M62 114C62 100 62 90 63 80" stroke="#FFE08A" stroke-width="1.6" opacity=".45" stroke-dasharray="3 4"/>' +
        burst(42, 46, 30, '#FBBF24', 14) + burst(82, 38, 26, '#F472B6', 12) + burst(64, 80, 20, '#38BDF8', 10) +
        '<circle cx="42" cy="46" r="5" fill="#FFF7D0"/><circle cx="82" cy="38" r="4.5" fill="#FFE4F1"/><circle cx="64" cy="80" r="4" fill="#E0F7FF"/>';
    },
    'rocket': function () {
      return '<defs>' + FL + '</defs>' + shadow(60, 20) + '<g transform="rotate(38 60 64)">' +
        '<path d="M49 90C48 104 60 120 60 120C60 120 72 104 71 90Z" fill="url(#§fl)"/>' +
        '<path d="M45 68L28 92L45 88Z" fill="url(#§l-red)"/><path d="M75 68L92 92L75 88Z" fill="url(#§l-red)"/>' +
        '<path d="M60 8C77 22 79 52 77 90H43C41 52 43 22 60 8Z" fill="url(#§h-silver)"/><path d="M60 8C68 15 72 24 73.5 31H46.5C48 24 52 15 60 8Z" fill="url(#§l-red)"/>' +
        '<circle cx="60" cy="50" r="10.5" fill="url(#§r-blue)" stroke="#CDD5E1" stroke-width="3.5"/>' + shine(56, 46, 3.5, 2.2, 0.8, -30) +
        '<rect x="47" y="87" width="26" height="6" rx="2" fill="#69758A"/><rect x="58" y="68" width="4" height="22" fill="#B0122A"/></g>';
    },
    'grand-stage': function () {
      return '<rect x="14" y="18" width="92" height="74" rx="4" fill="#24133A"/><path d="M40 18L22 92H58ZM80 18L62 92H98Z" fill="#FFE9A8" opacity=".3"/>' +
        '<path d="' + starPath(60, 60, 15, 6.4) + '" fill="url(#§r-gold)"/>' +
        '<path d="M8 14H44C38 40 40 66 30 96H8Z" fill="url(#§h-red)"/><path d="M112 14H76C82 40 80 66 90 96H112Z" fill="url(#§h-red)"/>' +
        '<path d="M18 18C20 46 18 70 16 94M30 18C32 44 28 70 24 94M102 18C100 46 102 70 104 94M90 18C88 44 92 70 96 94" stroke="#7E0C18" stroke-width="2" opacity=".5" fill="none"/>' +
        '<path d="M6 10H114V22C102 30 94 21 84 28C74 21 66 30 60 26C54 30 46 21 36 28C26 21 18 30 6 22Z" fill="url(#§l-red)"/><rect x="4" y="5" width="112" height="6" rx="3" fill="url(#§l-gold)"/>' +
        '<path d="M4 96H116L108 112H12Z" fill="url(#§l-brown)"/><rect x="4" y="93" width="112" height="5" rx="2" fill="url(#§l-gold)"/>';
    },
    'cake': function () {
      return '<defs>' + FL + '</defs><ellipse cx="60" cy="105" rx="47" ry="8" fill="url(#§l-silver)"/>' +
        '<rect x="20" y="68" width="80" height="35" rx="6" fill="url(#§h-pink)"/><path d="M20 70C24 80 28 80 30 72C34 84 40 84 42 72C46 80 52 80 54 72C58 86 64 86 66 72C70 80 76 80 78 72C82 82 88 82 90 72C94 80 98 78 100 70V65H20Z" fill="#FFFDF5"/>' +
        '<rect x="34" y="43" width="52" height="27" rx="5" fill="url(#§h-pink)"/><path d="M34 45C38 52 42 52 44 46C48 54 54 54 56 46C60 52 66 52 68 46C72 54 78 54 80 46C82 50 86 50 86 45V40H34Z" fill="#FFFDF5"/><ellipse cx="60" cy="41" rx="26" ry="6" fill="#FFFDF5"/>' +
        '<rect x="46" y="24" width="5" height="17" rx="1.5" fill="url(#§h-blue)"/><rect x="57.5" y="20" width="5" height="21" rx="1.5" fill="url(#§h-gold)"/><rect x="69" y="24" width="5" height="17" rx="1.5" fill="url(#§h-blue)"/>' +
        '<path d="M48.5 13C51.5 18 51.5 22 48.5 23C45.5 22 45.5 18 48.5 13ZM60 9C63 14 63 18 60 19C57 18 57 14 60 9ZM71.5 13C74.5 18 74.5 22 71.5 23C68.5 22 68.5 18 71.5 13Z" fill="url(#§fl)"/>' +
        '<circle cx="30" cy="66" r="4.5" fill="url(#§r-red)"/><circle cx="90" cy="66" r="4.5" fill="url(#§r-red)"/><circle cx="60" cy="66" r="4.5" fill="url(#§r-red)"/>' + shine(28, 84, 3, 9, 0.35);
    },
    'golden-drum': function () {
      return shadow(60, 36, 108) + '<path d="M30 14L62 40M92 12L64 38" stroke="url(#§l-brown)" stroke-width="5" stroke-linecap="round"/><circle cx="30" cy="14" r="5.5" fill="url(#§r-cream)"/><circle cx="92" cy="12" r="5.5" fill="url(#§r-cream)"/>' +
        '<path d="M26 46V90C26 101 94 101 94 90V46Z" fill="url(#§h-gold)"/><path d="M26 52L37 88L48 52L60 90L72 52L83 88L94 52" stroke="#C81E2E" stroke-width="3" fill="none" stroke-linejoin="round"/>' +
        '<path d="M26 88C26 98 94 98 94 88V94C94 104 26 104 26 94Z" fill="#B91C1C"/><ellipse cx="60" cy="46" rx="34" ry="12" fill="url(#§r-cream)"/><ellipse cx="60" cy="46" rx="34" ry="12" fill="none" stroke="#B91C1C" stroke-width="4"/>' + shine(36, 70, 3, 14, 0.35);
    },
    'dj-turntable': function () {
      return shadow(60, 48, 110) + '<rect x="8" y="34" width="104" height="68" rx="10" fill="url(#§l-dark)"/><rect x="8" y="34" width="104" height="68" rx="10" fill="none" stroke="#F6B91E" stroke-width="2.5"/>' +
        '<circle cx="48" cy="68" r="28" fill="#0B0B10"/><circle cx="48" cy="68" r="23" fill="none" stroke="#2D2D3A" stroke-width="1.5"/><circle cx="48" cy="68" r="18" fill="none" stroke="#2D2D3A" stroke-width="1.5"/><circle cx="48" cy="68" r="13" fill="none" stroke="#2D2D3A" stroke-width="1.5"/>' +
        '<circle cx="48" cy="68" r="8.5" fill="url(#§r-red)"/><circle cx="48" cy="68" r="1.8" fill="#ddd"/><path d="M28 54A26 26 0 0 1 52 42" stroke="#fff" opacity=".28" stroke-width="3" fill="none"/>' +
        '<path d="M97 44L92 76L76 84" stroke="url(#§l-silver)" stroke-width="4" fill="none" stroke-linecap="round"/><circle cx="97" cy="44" r="6.5" fill="url(#§r-silver)"/>' +
        '<circle cx="98" cy="90" r="4.5" fill="url(#§r-gold)"/><rect x="83" y="85" width="6" height="11" rx="2" fill="#38BDF8"/><rect x="16" y="94" width="5" height="3" rx="1" fill="#34D399"/><rect x="24" y="94" width="5" height="3" rx="1" fill="#FBBF24"/><rect x="32" y="94" width="5" height="3" rx="1" fill="#F0313F"/>' +
        '<path d="M14 20c3-6 9-6 12 0M100 18c3-6 9-6 12 0" stroke="#FFE08A" stroke-width="2.5" fill="none" stroke-linecap="round"/>';
    },
    'golden-football': function () {
      var patches = '', i, a;
      for (i = 0; i < 5; i++) { a = -Math.PI / 2 + i * 2 * Math.PI / 5; patches += '<path d="' + pent(60 + 44 * Math.cos(a), 60 + 44 * Math.sin(a), 13) + '"/>'; }
      return shadow(60, 34, 110) + '<defs><clipPath id="§bc"><circle cx="60" cy="60" r="44"/></clipPath></defs><circle cx="60" cy="60" r="44" fill="url(#§r-gold)"/>' +
        '<g clip-path="url(#§bc)" fill="#8A5206" opacity=".7">' + patches + '<path d="' + pent(60, 60, 13) + '"/></g>' +
        '<path d="M60 47V27M72.4 56L91 50M67.6 70.5L79 87M52.4 70.5L41 87M47.6 56L29 50" stroke="#8A5206" stroke-width="2.4" opacity=".6"/>' + shine(44, 38, 12, 7, 0.55, -35);
    },
    'car': function () {
      return shadow(60, 52, 104) + '<path d="M8 80C8 72 14 68 24 66L40 61C50 49 62 45 76 47C86 48 94 55 100 61C108 63 114 67 114 75V84C114 88 110 90 106 90H14C10 90 8 86 8 80Z" fill="url(#§l-red)"/>' +
        '<path d="M46 61C54 52 62 50 72 51L74 61ZM78 51C86 52 92 57 96 61H78Z" fill="url(#§l-blue)" opacity=".92"/><path d="M14 74H106" stroke="#fff" opacity=".35" stroke-width="2"/>' +
        '<path d="M106 68L113 70L112 74L104 73Z" fill="#FFF4B8"/><rect x="8" y="70" width="5" height="5" rx="1" fill="#FFD166"/>' +
        '<circle cx="32" cy="90" r="13" fill="#111"/><circle cx="32" cy="90" r="7.5" fill="url(#§r-gold)"/><circle cx="92" cy="90" r="13" fill="#111"/><circle cx="92" cy="90" r="7.5" fill="url(#§r-gold)"/>' +
        '<path d="M32 83V97M25 90H39M92 83V97M85 90H99" stroke="#9A5B05" stroke-width="1.5"/>' + shine(60, 56, 18, 2.5, 0.35);
    },
    'professional-camera': function () {
      return shadow(60, 46, 108) + '<path d="M38 38L46 24H74L82 38Z" fill="url(#§l-dark)"/><rect x="16" y="30" width="14" height="8" rx="2" fill="url(#§l-red)"/>' +
        '<rect x="10" y="38" width="100" height="62" rx="10" fill="url(#§l-dark)"/><rect x="10" y="38" width="22" height="62" rx="9" fill="#15151C"/><rect x="10" y="38" width="100" height="6" rx="3" fill="url(#§l-silver)" opacity=".8"/>' +
        '<rect x="92" y="48" width="12" height="8" rx="2" fill="url(#§l-blue)"/><circle cx="66" cy="70" r="27" fill="url(#§r-silver)"/><circle cx="66" cy="70" r="23" fill="none" stroke="#F6B91E" stroke-width="2"/>' +
        '<circle cx="66" cy="70" r="20" fill="#0F0F16"/><circle cx="66" cy="70" r="14" fill="url(#§r-blue)"/><circle cx="66" cy="70" r="6" fill="#0A1E3A"/>' + shine(60, 63, 5, 3, 0.75, -30);
    },
    'luxury-gold-watch': function () {
      var marks = '', i, a;
      for (i = 0; i < 12; i++) { a = i * Math.PI / 6; marks += '<path d="M' + (60 + 19 * Math.sin(a)).toFixed(1) + ' ' + (60 - 19 * Math.cos(a)).toFixed(1) + 'L' + (60 + (i % 3 ? 21.5 : 23) * Math.sin(a)).toFixed(1) + ' ' + (60 - (i % 3 ? 21.5 : 23) * Math.cos(a)).toFixed(1) + '"/>'; }
      return '<path d="M44 4H76L72 34H48Z" fill="url(#§h-gold)"/><path d="M47 86H73L77 116H43Z" fill="url(#§h-gold)"/><path d="M46 12H74M46 20H74M47 28H73M46 94H74M45 102H75M44 110H76" stroke="#9A5B05" stroke-width="1.4" opacity=".5"/>' +
        '<rect x="90" y="55" width="9" height="10" rx="2" fill="url(#§l-gold)"/><circle cx="60" cy="60" r="33" fill="url(#§r-gold)"/><circle cx="60" cy="60" r="25" fill="url(#§r-cream)"/>' +
        '<g stroke="#5B4A2A" stroke-width="2" stroke-linecap="round">' + marks + '</g>' +
        '<path d="M60 60V43M60 60L72 66" stroke="#1F2937" stroke-width="3" stroke-linecap="round"/><path d="M60 66V40" stroke="#F0313F" stroke-width="1.2"/><circle cx="60" cy="60" r="2.6" fill="#1F2937"/>' +
        '<circle cx="60" cy="29.5" r="2" fill="#fff"/><circle cx="90.5" cy="60" r="0" fill="#fff"/><circle cx="29.5" cy="60" r="2" fill="#fff"/><circle cx="60" cy="90.5" r="2" fill="#fff"/>' + shine(46, 44, 8, 4, 0.55, -40) + twinkle(100, 26, 6, '#fff');
    },
    'jet': function () {
      var w = '', i;
      for (i = 0; i < 6; i++) { w += '<circle cx="' + (50 + i * 8) + '" cy="59.5" r="2.6"/>'; }
      return '<g transform="rotate(-12 60 62)">' + '<path d="M56 54L46 40H54L72 54Z" fill="#9AA4B5"/>' +
        '<path d="M10 64C10 56 24 52 40 52H92C104 52 114 58 114 62C114 66 104 70 92 70H40C24 70 10 70 10 64Z" fill="url(#§l-silver)"/>' +
        '<path d="M98 55C104 56 108 58 110 60H100Z" fill="url(#§l-blue)"/><g fill="#1E3A5F">' + w + '</g><path d="M22 65H104" stroke="#F6B91E" stroke-width="2.5"/>' +
        '<path d="M18 54L8 28H20L36 52Z" fill="url(#§l-silver)"/><path d="M10 30H19L22 38H13Z" fill="#F6B91E"/><rect x="24" y="47" width="18" height="8" rx="4" fill="url(#§l-silver)"/>' +
        '<path d="M52 66L36 94H48L76 66Z" fill="url(#§l-silver)"/>' + shine(70, 55, 22, 1.6, 0.6) + '</g>';
    },
    'luxury-yacht': function () {
      return '<path d="M0 98C14 92 26 104 40 98C54 92 66 104 80 98C94 92 106 104 120 98V118H0Z" fill="url(#§l-blue)" opacity=".85"/>' +
        '<rect x="60" y="22" width="3" height="20" fill="#9AA4B5"/><path d="M63 22L74 26L63 30Z" fill="#F6B91E"/><circle cx="72" cy="40" r="4" fill="#fff"/>' +
        '<path d="M40 54L46 42H80L86 54Z" fill="url(#§l-silver)"/><path d="M48 45H78L81 51H45Z" fill="url(#§l-dark)"/>' +
        '<path d="M24 70L32 54H92L100 70Z" fill="url(#§l-silver)"/><path d="M36 58H88L92 66H32Z" fill="url(#§l-dark)"/>' +
        '<path d="M6 70H113C109 84 101 94 87 98H24C16 92 10 82 6 70Z" fill="url(#§l-silver)"/><path d="M14 86H104C100 92 94 96 87 98H24C20 94 16 90 14 86Z" fill="#1E293B"/><path d="M8 76H111" stroke="#F6B91E" stroke-width="3"/>' +
        '<path d="M8 104C22 100 30 108 44 104M70 108C84 104 92 112 106 108" stroke="#fff" stroke-width="2" opacity=".5" fill="none" stroke-linecap="round"/>' + shine(50, 62, 18, 1.5, 0.5);
    },
    'royal-castle': function () {
      var cren = '', i;
      for (i = 0; i < 6; i++) { cren += '<rect x="' + (31 + i * 10.4) + '" y="56" width="6" height="8"/>'; }
      return shadow(60, 50, 110) + '<rect x="30" y="62" width="60" height="46" fill="url(#§l-cream)"/><g fill="#E9D7B4">' + cren + '</g>' +
        '<rect x="12" y="44" width="24" height="64" fill="url(#§h-cream)"/><rect x="84" y="44" width="24" height="64" fill="url(#§h-cream)"/><rect x="45" y="30" width="30" height="78" fill="url(#§h-cream)"/>' +
        '<path d="M8 46L24 12L40 46Z" fill="url(#§l-purple)"/><path d="M80 46L96 12L112 46Z" fill="url(#§l-purple)"/><path d="M41 32L60 0L79 32Z" fill="url(#§l-purple)"/>' +
        '<path d="M8 46H40M80 46H112M41 32H79" stroke="#F6B91E" stroke-width="3"/><path d="M24 12V4L32 7L24 10M96 12V4L104 7L96 10" stroke="#F6B91E" stroke-width="1.6" fill="#F6B91E"/>' +
        '<path d="M50 108V90C50 80 70 80 70 90V108Z" fill="url(#§l-brown)"/><path d="M60 82V108" stroke="#4A2410" stroke-width="1.5"/>' +
        '<path d="M20 60v-4a4 4 0 0 1 8 0v4ZM92 60v-4a4 4 0 0 1 8 0v4ZM56 52v-6a4 4 0 0 1 8 0v6ZM20 80v-4a4 4 0 0 1 8 0v4ZM92 80v-4a4 4 0 0 1 8 0v4Z" fill="#FBBF24"/>' + twinkle(104, 70, 4, '#fff') + twinkle(14, 26, 4, '#fff');
    },
    'golden-dragon': function () {
      return shadow(56, 44, 113) + '<path d="M46 54C40 28 56 9 78 6C70 16 72 24 80 26C72 30 72 38 80 42C70 44 62 49 58 57Z" fill="url(#§l-orange)"/>' +
        '<path d="M8 96C24 104 40 96 46 82C52 66 60 60 74 60C86 60 92 54 96 46L104 50C100 64 90 72 76 74C66 75 60 82 56 92C48 110 22 114 8 104Z" fill="url(#§r-gold)"/>' +
        '<path d="M8 96L1 89L3 103Z" fill="url(#§l-gold)"/><path d="M18 100C30 102 42 96 48 86C54 72 62 66 74 66" stroke="#FFF4B8" stroke-width="3" stroke-dasharray="4 4" fill="none" opacity=".7"/>' +
        '<path d="M30 98l2-8 4 7M42 90l3-8 3 7M52 76l4-7 2 8M66 64l5-6 1 8M80 62l5-5 0 8" fill="#C98A0C"/>' +
        '<path d="M92 46C96 34 106 32 114 38L118 46C112 46 110 50 112 54C104 56 98 54 94 52Z" fill="url(#§r-gold)"/><path d="M98 37L93 22L104 35ZM104 35L107 20L110 36Z" fill="url(#§l-gold)"/>' +
        '<circle cx="106" cy="42" r="2.4" fill="#E2370C"/><path d="M112 50C108 51 104 50 102 48" stroke="#9A5B05" stroke-width="1.4" fill="none"/>' +
        '<path d="M62 63C56 37 70 20 92 18C84 28 86 34 94 38C86 40 84 48 90 52C80 54 72 58 68 67Z" fill="url(#§r-gold)"/><path d="M70 60C68 44 76 32 88 26" stroke="#9A5B05" stroke-width="1.5" fill="none" opacity=".5"/>' +
        '<path d="M60 92l-4 10M66 82l-2 10" stroke="#9A5B05" stroke-width="3" stroke-linecap="round"/>' + shine(78, 64, 8, 2.5, 0.45, -10);
    },
    'world-tour': function () {
      return '<defs><clipPath id="§gc"><circle cx="60" cy="60" r="36"/></clipPath></defs>' +
        '<g transform="rotate(-18 60 62)"><path d="M8 62A52 16 0 0 1 112 62" stroke="#F6B91E" stroke-width="2.5" fill="none" stroke-dasharray="5 5" opacity=".55"/></g>' +
        '<circle cx="60" cy="60" r="36" fill="url(#§r-blue)"/><g clip-path="url(#§gc)" fill="url(#§r-green)"><path d="M34 40C42 30 56 32 58 42C60 50 50 52 48 60C46 68 36 66 34 58C30 52 28 46 34 40Z"/><path d="M66 58C74 52 88 56 90 66C92 76 82 86 74 84C68 82 62 70 66 58Z"/><path d="M62 30C68 26 78 28 80 34C74 36 68 38 62 30Z"/><path d="M40 82C46 80 52 86 48 92C44 94 38 88 40 82Z"/></g>' +
        '<ellipse cx="60" cy="60" rx="16" ry="36" fill="none" stroke="#fff" opacity=".2"/><path d="M24 60H96" stroke="#fff" opacity=".2"/>' + shine(46, 42, 9, 5, 0.45, -35) +
        '<g transform="rotate(-18 60 62)"><path d="M112 62A52 16 0 0 1 8 62" stroke="#F6B91E" stroke-width="2.5" fill="none" stroke-dasharray="5 5"/></g>' +
        '<g transform="translate(103 44) rotate(-30)"><path d="M-9 0L9 0M0 -1L-4 -7H-1L5 -1M0 1L-4 7H-1L5 1M-8 -1L-10 -4H-8L-6 -1" stroke="#fff" stroke-width="2.4" stroke-linecap="round" fill="none"/></g>' +
        '<path d="M78 22C78 12 92 12 92 22C92 30 85 36 85 41C85 36 78 30 78 22Z" fill="url(#§r-red)"/><circle cx="85" cy="22" r="3" fill="#fff"/>';
    },
    'golden-throne': function () {
      var tufts = '<circle cx="50" cy="30" r="1.8"/><circle cx="60" cy="26" r="1.8"/><circle cx="70" cy="30" r="1.8"/><circle cx="50" cy="46" r="1.8"/><circle cx="60" cy="42" r="1.8"/><circle cx="70" cy="46" r="1.8"/><circle cx="55" cy="58" r="1.8"/><circle cx="65" cy="58" r="1.8"/>';
      return shadow(60, 42, 113) + '<path d="M30 72V26C30 10 46 5 60 5C74 5 90 10 90 26V72Z" fill="url(#§r-gold)"/><path d="M38 68V29C38 17 50 13 60 13C70 13 82 17 82 29V68Z" fill="url(#§l-red)"/>' +
        '<g fill="#7E0C18">' + tufts + '</g><path d="M60 0L67 9L60 18L53 9Z" fill="url(#§r-blue)"/>' +
        '<path d="M30 94L26 112H36L38 94ZM90 94L94 112H84L82 94Z" fill="url(#§l-gold)"/><rect x="26" y="82" width="68" height="13" rx="3" fill="url(#§l-gold)"/>' +
        '<path d="M24 70H96C98 76 96 82 92 85H28C24 82 22 76 24 70Z" fill="url(#§l-red)"/>' +
        '<path d="M14 62C14 54 30 54 30 62V88H18C14 88 14 82 14 78Z" fill="url(#§r-gold)"/><path d="M106 62C106 54 90 54 90 62V88H102C106 88 106 82 106 78Z" fill="url(#§r-gold)"/>' +
        '<circle cx="22" cy="57" r="5" fill="url(#§r-gold)"/><circle cx="98" cy="57" r="5" fill="url(#§r-gold)"/>' + shine(38, 24, 3, 10, 0.4) + twinkle(102, 16, 6) + twinkle(18, 26, 4);
    },
    'supernova': function () {
      return '<defs><radialGradient id="§nv" cx="50%" cy="50%" r="50%"><stop offset="0" stop-color="#fff"/><stop offset=".22" stop-color="#FDE68A"/><stop offset=".45" stop-color="#F472B6" stop-opacity=".85"/><stop offset=".72" stop-color="#7C3AED" stop-opacity=".55"/><stop offset="1" stop-color="#1E1B4B" stop-opacity="0"/></radialGradient></defs>' +
        '<circle cx="60" cy="60" r="58" fill="url(#§nv)"/>' + rays(60, 60, 26, 56, 16, '#FDE68A', 2, 0.7) +
        '<path d="' + starPath(60, 60, 50, 16, 8) + '" fill="#FFF7D0" opacity=".9"/><ellipse cx="60" cy="60" rx="50" ry="13" transform="rotate(-22 60 60)" fill="none" stroke="#A78BFA" stroke-width="3" opacity=".85"/>' +
        '<ellipse cx="60" cy="60" rx="44" ry="10" transform="rotate(32 60 60)" fill="none" stroke="#38BDF8" stroke-width="2.4" opacity=".75"/><circle cx="60" cy="60" r="13" fill="#fff"/>' + twinkle(16, 18, 5, '#fff') + twinkle(104, 100, 5, '#fff') + twinkle(102, 18, 4, '#FDE68A');
    },
    'grand-finale': function () {
      return rays(60, 92, 30, 90, 22, '#F6B91E', 3, 0.35) + burst(30, 34, 22, '#F472B6', 12) + burst(90, 30, 24, '#38BDF8', 12) + burst(60, 18, 16, '#34D399', 10) + burst(60, 56, 28, '#FBBF24', 16) +
        '<circle cx="60" cy="56" r="7" fill="#FFF7D0"/><path d="' + starPath(60, 96, 16, 7) + '" fill="url(#§r-gold)"/>' +
        '<rect x="16" y="76" width="7" height="4" rx="1" fill="#F0313F" transform="rotate(30 19 78)"/><rect x="98" y="72" width="7" height="4" rx="1" fill="#9B5CF6" transform="rotate(-30 101 74)"/><rect x="34" y="96" width="7" height="4" rx="1" fill="#38BDF8" transform="rotate(60 37 98)"/><rect x="84" y="100" width="7" height="4" rx="1" fill="#F472B6" transform="rotate(20 87 102)"/>';
    },
    'obi-universe': function () {
      var dots = [[18, 30, 1.6], [30, 96, 1.3], [96, 18, 1.8], [104, 84, 1.4], [84, 104, 1.2], [12, 66, 1.2], [70, 10, 1.2], [44, 16, 1]], st = '', i;
      for (i = 0; i < dots.length; i++) { st += '<circle cx="' + dots[i][0] + '" cy="' + dots[i][1] + '" r="' + dots[i][2] + '"/>'; }
      return '<defs><radialGradient id="§ug" cx="50%" cy="50%" r="50%"><stop offset="0" stop-color="#C4B5FD" stop-opacity=".95"/><stop offset=".35" stop-color="#7C3AED" stop-opacity=".75"/><stop offset=".7" stop-color="#1D4ED8" stop-opacity=".35"/><stop offset="1" stop-color="#0B1030" stop-opacity="0"/></radialGradient>' +
        '<radialGradient id="§uc" cx="40%" cy="35%" r="70%"><stop offset="0" stop-color="#FFFFFF"/><stop offset=".45" stop-color="#FFE98F"/><stop offset="1" stop-color="#E0A10C"/></radialGradient></defs>' +
        '<circle cx="60" cy="60" r="58" fill="url(#§ug)"/><g fill="#fff">' + st + '</g>' + twinkle(100, 40, 4, '#FDE68A') + twinkle(22, 84, 4, '#7DD3FC') +
        '<g transform="rotate(-14 60 62)"><path d="M6 62A54 15 0 0 1 114 62" stroke="url(#§l-gold)" stroke-width="4" fill="none" opacity=".85"/></g>' +
        '<path d="M60 60C77 52 85 36 71 26C59 18 41 24 35 38" stroke="#7DD3FC" stroke-width="6" fill="none" stroke-linecap="round" opacity=".85"/><path d="M60 60C43 68 35 84 49 94C61 102 79 96 85 82" stroke="#C084FC" stroke-width="6" fill="none" stroke-linecap="round" opacity=".85"/>' +
        '<path d="M60 60C72 56 78 46 72 38M60 60C48 64 42 74 48 82" stroke="#FDE68A" stroke-width="2.5" fill="none" stroke-linecap="round"/>' +
        '<circle cx="60" cy="60" r="15" fill="url(#§uc)"/><text x="60" y="64.2" text-anchor="middle" font-family="Outfit, \'Plus Jakarta Sans\', system-ui, sans-serif" font-weight="900" font-size="11.5" letter-spacing=".4" fill="#3B1585">OBI</text>' +
        '<g transform="rotate(-14 60 62)"><path d="M114 62A54 15 0 0 1 6 62" stroke="url(#§l-gold)" stroke-width="4" fill="none"/></g>';
    }
  };

  function svg(slug) {
    var f = ART[slug];
    if (!f) { return ''; }
    var body = f(), id = 'oga' + (++seq) + '-';
    var defs = defsFor(body);
    var out = '<svg viewBox="0 0 120 120" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">' + (defs ? '<defs>' + defs + '</defs>' : '') + body + '</svg>';
    return out.replace(/§/g, id);
  }
  window.ObGiftArt = { has: function (slug) { return !!ART[slug]; }, svg: svg, slugs: function () { return Object.keys(ART); } };
})();
