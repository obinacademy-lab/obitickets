<?php
/*
 * Fragmented MP4 -> normal MP4, without re-encoding (11/27).
 * A video recorded by a browser (MediaRecorder) is a "fragmented" MP4: the header says the length is 0 and the samples are spread over
 * many small fragments (moof + mdat). Many phones (iPhones especially) cannot play such a file from a plain link, and others download
 * piece after piece just to find its length. This rewrites it as an ordinary MP4: one header (moov, at the start, with the real length
 * and full sample tables) and one block of media data. The picture and sound bytes are copied exactly as they are: same quality, same size
 * (give or take a few KB of header). Works for the H.264/AAC files browsers make; anything it does not understand is left untouched.
 */

const MP4_MAX_HEADER_BYTES = 64 * 1024 * 1024;   // a moov or moof larger than this is not something we wrote: leave the file alone

/** Top-level boxes of a file: list of [type, start, size, headerSize]. */
function mp4_top_boxes($fh, int $fileSize): array
{
    $out = [];
    $pos = 0;
    while ($pos + 8 <= $fileSize) {
        fseek($fh, $pos);
        $h = fread($fh, 16);
        if (strlen($h) < 8) {
            break;
        }
        $size = unpack('N', substr($h, 0, 4))[1];
        $type = substr($h, 4, 4);
        $hs = 8;
        if ($size === 1) {
            if (strlen($h) < 16) {
                break;
            }
            $size = unpack('J', substr($h, 8, 8))[1];
            $hs = 16;
        } elseif ($size === 0) {
            $size = $fileSize - $pos;
        }
        if ($size < $hs || $pos + $size > $fileSize) {
            break;   // a cut-off last box: stop here
        }
        $out[] = [$type, $pos, $size, $hs];
        $pos += $size;
    }
    return $out;
}

/** Child boxes inside a box held in memory: list of [type, contentStart, contentEnd, boxStart]. */
function mp4_children(string $buf, int $from, int $to): array
{
    $out = [];
    $p = $from;
    while ($p + 8 <= $to) {
        $size = unpack('N', substr($buf, $p, 4))[1];
        $type = substr($buf, $p + 4, 4);
        $hs = 8;
        if ($size === 1) {
            $size = unpack('J', substr($buf, $p + 8, 8))[1];
            $hs = 16;
        } elseif ($size === 0) {
            $size = $to - $p;
        }
        if ($size < $hs || $p + $size > $to) {
            break;
        }
        $out[] = [$type, $p + $hs, $p + $size, $p];
        $p += $size;
    }
    return $out;
}

function mp4_find(array $children, string $type): ?array
{
    foreach ($children as $c) {
        if ($c[0] === $type) {
            return $c;
        }
    }
    return null;
}

function mp4_u32(string $b, int $p): int { return unpack('N', substr($b, $p, 4))[1]; }
function mp4_u64(string $b, int $p): int { return unpack('J', substr($b, $p, 8))[1]; }
function mp4_s32(string $b, int $p): int { $v = mp4_u32($b, $p); return $v >= 0x80000000 ? $v - 0x100000000 : $v; }
function mp4_box(string $type, string $payload): string { return pack('N', 8 + strlen($payload)) . $type . $payload; }
function mp4_full(string $type, int $version, int $flags, string $payload): string { return mp4_box($type, chr($version) . substr(pack('N', $flags), 1) . $payload); }

/** True when the file is a fragmented MP4 (its moov has an mvex and moof fragments follow). */
function mp4_is_fragmented(string $path): bool
{
    $size = @filesize($path);
    $fh = $size ? @fopen($path, 'rb') : false;
    if (!$fh) {
        return false;
    }
    try {
        $boxes = mp4_top_boxes($fh, (int) $size);
        $moov = null; $moof = false;
        foreach ($boxes as $b) {
            if ($b[0] === 'moov') { $moov = $b; }
            if ($b[0] === 'moof') { $moof = true; }
        }
        if (!$moov || !$moof || $moov[2] > MP4_MAX_HEADER_BYTES) {
            return false;
        }
        fseek($fh, $moov[1]);
        $buf = fread($fh, $moov[2]);
        return mp4_find(mp4_children($buf, $moov[3], strlen($buf)), 'mvex') !== null;
    } finally {
        fclose($fh);
    }
}

/**
 * Rewrites a fragmented MP4 as a normal one.
 * @return array{ok:bool, error?:string, seconds?:float, samples?:int}
 */
function mp4_defragment(string $in, string $out): array
{
    $fileSize = (int) @filesize($in);
    $fh = $fileSize ? @fopen($in, 'rb') : false;
    if (!$fh) {
        return ['ok' => false, 'error' => 'cannot read the file'];
    }
    try {
        $boxes = mp4_top_boxes($fh, $fileSize);
        $moovBox = null;
        foreach ($boxes as $b) {
            if ($b[0] === 'moov') { $moovBox = $b; break; }
        }
        if (!$moovBox || $moovBox[2] > MP4_MAX_HEADER_BYTES) {
            return ['ok' => false, 'error' => 'no usable moov'];
        }
        fseek($fh, $moovBox[1]);
        $moov = fread($fh, $moovBox[2]);
        $mk = mp4_children($moov, $moovBox[3], strlen($moov));
        $mvhd = mp4_find($mk, 'mvhd');
        $mvex = mp4_find($mk, 'mvex');
        if (!$mvhd || !$mvex) {
            return ['ok' => false, 'error' => 'not fragmented'];
        }
        $mvhdVer = ord($moov[$mvhd[1]]);
        $movieTs = mp4_u32($moov, $mvhd[1] + ($mvhdVer === 1 ? 20 : 12));
        // defaults per track (trex)
        $trex = [];
        foreach (mp4_children($moov, $mvex[1], $mvex[2]) as $c) {
            if ($c[0] === 'trex') {
                $p = $c[1] + 4;
                $trex[mp4_u32($moov, $p)] = ['desc' => mp4_u32($moov, $p + 4), 'dur' => mp4_u32($moov, $p + 8), 'size' => mp4_u32($moov, $p + 12), 'flags' => mp4_u32($moov, $p + 16)];
            }
        }
        // the tracks
        $tracks = [];
        foreach ($mk as $c) {
            if ($c[0] !== 'trak') { continue; }
            $tk = mp4_children($moov, $c[1], $c[2]);
            $tkhd = mp4_find($tk, 'tkhd');
            $mdia = mp4_find($tk, 'mdia');
            if (!$tkhd || !$mdia) { return ['ok' => false, 'error' => 'odd track']; }
            $tkVer = ord($moov[$tkhd[1]]);
            $id = mp4_u32($moov, $tkhd[1] + ($tkVer === 1 ? 20 : 12));
            $md = mp4_children($moov, $mdia[1], $mdia[2]);
            $mdhd = mp4_find($md, 'mdhd'); $hdlr = mp4_find($md, 'hdlr'); $minf = mp4_find($md, 'minf');
            if (!$mdhd || !$hdlr || !$minf) { return ['ok' => false, 'error' => 'odd media'] ; }
            $mi = mp4_children($moov, $minf[1], $minf[2]);
            $stbl = mp4_find($mi, 'stbl');
            $stsd = $stbl ? mp4_find(mp4_children($moov, $stbl[1], $stbl[2]), 'stsd') : null;
            if (!$stsd) { return ['ok' => false, 'error' => 'no sample description']; }
            $mdVer = ord($moov[$mdhd[1]]);
            $tracks[$id] = [
                'tkhd' => substr($moov, $tkhd[3], $tkhd[2] - $tkhd[3]),
                'mdhd' => substr($moov, $mdhd[3], $mdhd[2] - $mdhd[3]),
                'hdlr' => substr($moov, $hdlr[3], $hdlr[2] - $hdlr[3]),
                'handler' => substr($moov, $hdlr[1] + 8, 4),
                'minfOther' => implode('', array_map(static fn ($x) => substr($moov, $x[3], $x[2] - $x[3]), array_filter($mi, static fn ($x) => $x[0] !== 'stbl'))),
                'stsd' => substr($moov, $stsd[3], $stsd[2] - $stsd[3]),
                'ts' => mp4_u32($moov, $mdhd[1] + ($mdVer === 1 ? 20 : 12)),
                'sizes' => [], 'durs' => [], 'ctos' => [], 'sync' => [], 'chunks' => [],   // chunks: [pos, len, samples]
            ];
        }
        if (!$tracks) {
            return ['ok' => false, 'error' => 'no tracks'];
        }
        $otherMoov = implode('', array_map(static fn ($x) => substr($moov, $x[3], $x[2] - $x[3]), array_filter($mk, static fn ($x) => !in_array($x[0], ['mvhd', 'trak', 'mvex'], true))));

        // walk the fragments
        $blocks = [];   // [pos, len] in file order
        foreach ($boxes as $b) {
            if ($b[0] !== 'moof') { continue; }
            if ($b[2] > MP4_MAX_HEADER_BYTES) { return ['ok' => false, 'error' => 'huge fragment header']; }
            fseek($fh, $b[1]);
            $mf = fread($fh, $b[2]);
            $moofStart = $b[1];
            $nextImplicit = $moofStart;
            foreach (mp4_children($mf, $b[3], strlen($mf)) as $traf) {
                if ($traf[0] !== 'traf') { continue; }
                $tc = mp4_children($mf, $traf[1], $traf[2]);
                $tfhd = mp4_find($tc, 'tfhd');
                if (!$tfhd) { return ['ok' => false, 'error' => 'no tfhd']; }
                $tf = mp4_u32($mf, $tfhd[1]) & 0xFFFFFF;
                $p = $tfhd[1] + 4;
                $tid = mp4_u32($mf, $p); $p += 4;
                if (!isset($tracks[$tid])) { return ['ok' => false, 'error' => 'unknown track']; }
                $def = $trex[$tid] ?? ['desc' => 1, 'dur' => 0, 'size' => 0, 'flags' => 0];
                $base = null;
                if ($tf & 0x1) { $base = mp4_u64($mf, $p); $p += 8; }
                if ($tf & 0x2) { $p += 4; }
                $dDur = $def['dur']; $dSize = $def['size']; $dFlags = $def['flags'];
                if ($tf & 0x8) { $dDur = mp4_u32($mf, $p); $p += 4; }
                if ($tf & 0x10) { $dSize = mp4_u32($mf, $p); $p += 4; }
                if ($tf & 0x20) { $dFlags = mp4_u32($mf, $p); $p += 4; }
                if ($base === null) { $base = ($tf & 0x20000) ? $moofStart : $nextImplicit; }
                $dataPos = $base;
                $first = true;
                foreach ($tc as $tr) {
                    if ($tr[0] !== 'trun') { continue; }
                    $ver = ord($mf[$tr[1]]);
                    $fl = mp4_u32($mf, $tr[1]) & 0xFFFFFF;
                    $q = $tr[1] + 4;
                    $count = mp4_u32($mf, $q); $q += 4;
                    if ($fl & 0x1) { $dataPos = $base + mp4_s32($mf, $q); $q += 4; }
                    elseif (!$first) { /* continues right after the previous run */ }
                    $firstFlags = null;
                    if ($fl & 0x4) { $firstFlags = mp4_u32($mf, $q); $q += 4; }
                    $runStart = $dataPos; $runLen = 0;
                    for ($i = 0; $i < $count; $i++) {
                        $dur = $dDur; $size = $dSize; $flags = $dFlags; $cto = 0;
                        if ($fl & 0x100) { $dur = mp4_u32($mf, $q); $q += 4; }
                        if ($fl & 0x200) { $size = mp4_u32($mf, $q); $q += 4; }
                        if ($fl & 0x400) { $flags = mp4_u32($mf, $q); $q += 4; } elseif ($i === 0 && $firstFlags !== null) { $flags = $firstFlags; }
                        if ($fl & 0x800) { $cto = $ver === 0 ? mp4_u32($mf, $q) : mp4_s32($mf, $q); $q += 4; }
                        $t = &$tracks[$tid];
                        $t['sizes'][] = $size; $t['durs'][] = $dur; $t['ctos'][] = $cto; $t['sync'][] = ($flags & 0x10000) === 0;
                        unset($t);
                        $runLen += $size;
                    }
                    if ($count > 0) {
                        if ($runStart < 0 || $runStart + $runLen > $fileSize) { return ['ok' => false, 'error' => 'samples outside the file']; }
                        $tracks[$tid]['chunks'][] = [$runStart, $runLen, $count];
                        $blocks[] = [$runStart, $runLen];
                    }
                    $dataPos = $runStart + $runLen;
                    $first = false;
                }
                $nextImplicit = $dataPos;
            }
        }
        foreach ($tracks as $t) {
            if (!$t['sizes']) { return ['ok' => false, 'error' => 'a track has no samples']; }
        }
        // where each block goes in the new file: the same order as in the old one, packed together
        usort($blocks, static fn ($a, $b) => $a[0] <=> $b[0]);
        $build = function (int $dataStart) use ($tracks, $blocks, $movieTs, $moov, $mvhd, $mvhdVer, $otherMoov): array {
            $newPos = []; $at = $dataStart;
            foreach ($blocks as $bl) { $newPos[$bl[0]] = $at; $at += $bl[1]; }
            $traks = ''; $movieDur = 0;
            foreach ($tracks as $t) {
                $n = count($t['sizes']);
                $mediaDur = array_sum($t['durs']);
                $trackDur = $t['ts'] > 0 ? (int) round($mediaDur * $movieTs / $t['ts']) : 0;
                $movieDur = max($movieDur, $trackDur);
                // stts
                $stts = []; $prev = null;
                foreach ($t['durs'] as $d) { if ($prev !== null && $stts[count($stts) - 1][1] === $d) { $stts[count($stts) - 1][0]++; } else { $stts[] = [1, $d]; } $prev = $d; }
                $sttsB = pack('N', count($stts)) . implode('', array_map(static fn ($e) => pack('NN', $e[0], $e[1]), $stts));
                // ctts (only when some sample has an offset)
                $cttsB = '';
                if (array_filter($t['ctos'], static fn ($c) => $c !== 0)) {
                    $neg = (bool) array_filter($t['ctos'], static fn ($c) => $c < 0);
                    $runs = [];
                    foreach ($t['ctos'] as $c) { if ($runs && $runs[count($runs) - 1][1] === $c) { $runs[count($runs) - 1][0]++; } else { $runs[] = [1, $c]; } }
                    $cttsB = mp4_full('ctts', $neg ? 1 : 0, 0, pack('N', count($runs)) . implode('', array_map(static fn ($e) => pack('N', $e[0]) . pack('N', $e[1] & 0xFFFFFFFF), $runs)));
                }
                // stss (only when not every sample is a key frame)
                $stssB = '';
                if (in_array(false, $t['sync'], true)) {
                    $keys = [];
                    foreach ($t['sync'] as $i => $s) { if ($s) { $keys[] = $i + 1; } }
                    $stssB = mp4_full('stss', 0, 0, pack('N', count($keys)) . implode('', array_map(static fn ($k) => pack('N', $k), $keys)));
                }
                // stsz
                $same = count(array_unique($t['sizes'])) === 1;
                $stszB = $same ? pack('NN', $t['sizes'][0], $n) : pack('NN', 0, $n) . implode('', array_map(static fn ($s) => pack('N', $s), $t['sizes']));
                // stsc + co64
                $stsc = []; $co = '';
                foreach ($t['chunks'] as $ci => $ch) {
                    if (!$stsc || $stsc[count($stsc) - 1][1] !== $ch[2]) { $stsc[] = [$ci + 1, $ch[2], 1]; }
                    $co .= pack('J', $newPos[$ch[0]]);
                }
                $stscB = pack('N', count($stsc)) . implode('', array_map(static fn ($e) => pack('NNN', $e[0], $e[1], $e[2]), $stsc));
                $stbl = mp4_box('stbl', $t['stsd'] . mp4_full('stts', 0, 0, $sttsB) . $cttsB . $stssB . mp4_full('stsc', 0, 0, $stscB) . mp4_full('stsz', 0, 0, $stszB) . mp4_full('co64', 0, 0, pack('N', count($t['chunks'])) . $co));
                // tkhd and mdhd with the real lengths
                $tkhd = $t['tkhd']; $tv = ord($tkhd[8]);
                $tkhd = $tv === 1 ? substr_replace($tkhd, pack('J', $trackDur), 8 + 4 + 24, 8) : substr_replace($tkhd, pack('N', min($trackDur, 0xFFFFFFFF)), 8 + 4 + 16, 4);
                $mdhd = $t['mdhd']; $mv = ord($mdhd[8]);
                $mdhd = $mv === 1 ? substr_replace($mdhd, pack('J', $mediaDur), 8 + 4 + 20, 8) : substr_replace($mdhd, pack('N', min($mediaDur, 0xFFFFFFFF)), 8 + 4 + 12, 4);
                $traks .= mp4_box('trak', $tkhd . mp4_box('mdia', $mdhd . $t['hdlr'] . mp4_box('minf', $t['minfOther'] . $stbl)));
            }
            $mvhdB = substr($moov, $mvhd[3], $mvhd[2] - $mvhd[3]);
            $mvhdB = $mvhdVer === 1 ? substr_replace($mvhdB, pack('J', $movieDur), 8 + 4 + 20, 8) : substr_replace($mvhdB, pack('N', min($movieDur, 0xFFFFFFFF)), 8 + 4 + 12, 4);
            return [mp4_box('moov', $mvhdB . $traks . $otherMoov), $movieDur];
        };
        $ftyp = mp4_box('ftyp', 'isom' . pack('N', 512) . 'isomiso2avc1mp41');
        $dataLen = array_sum(array_map(static fn ($b) => $b[1], $blocks));
        $mdatHead = $dataLen + 8 <= 0xFFFFFFFF ? pack('N', $dataLen + 8) . 'mdat' : pack('N', 1) . 'mdat' . pack('J', $dataLen + 16);
        [$moovB] = $build(0);                                   // the header's size does not depend on the offsets (co64)
        $dataStart = strlen($ftyp) + strlen($moovB) + strlen($mdatHead);
        [$moovB, $movieDur] = $build($dataStart);
        $oh = @fopen($out, 'wb');
        if (!$oh) {
            return ['ok' => false, 'error' => 'cannot write'];
        }
        $ok = fwrite($oh, $ftyp . $moovB . $mdatHead) !== false;
        foreach ($blocks as $bl) {
            if (!$ok) { break; }
            fseek($fh, $bl[0]);
            $ok = stream_copy_to_stream($fh, $oh, $bl[1]) === $bl[1];
        }
        fclose($oh);
        if (!$ok || filesize($out) !== $dataStart + $dataLen) {
            @unlink($out);
            return ['ok' => false, 'error' => 'copy failed'];
        }
        $samples = array_sum(array_map(static fn ($t) => count($t['sizes']), $tracks));
        return ['ok' => true, 'seconds' => $movieTs > 0 ? round($movieDur / $movieTs, 2) : 0.0, 'samples' => $samples];
    } finally {
        fclose($fh);
    }
}

/** Rewrites a fragmented MP4 in place (through a temporary file next to it). Untouched when it is not fragmented or anything fails. */
function mp4_defragment_in_place(string $path): array
{
    if (!mp4_is_fragmented($path)) {
        return ['ok' => true, 'changed' => false];
    }
    $tmp = $path . '.fix-' . bin2hex(random_bytes(4));
    $r = mp4_defragment($path, $tmp);
    if (!$r['ok']) {
        @unlink($tmp);
        return $r + ['changed' => false];
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return ['ok' => false, 'error' => 'could not replace the file', 'changed' => false];
    }
    return $r + ['changed' => true];
}
