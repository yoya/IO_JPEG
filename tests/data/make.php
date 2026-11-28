<?php
/*
 * generate synthetic JPEG marker streams (no real images)
 * usage: php tests/data/make.php [outdir]   (default: this directory)
 *   ref) ITU-T T.81 (JPEG), JFIF 1.02, ITU-T T.872 (Adobe APP14), Exif 2.3, ICC.1 (APP2)
 *   the entropy-coded data are dummy bytes (0xFF is always stuffed as FF 00).
 */

$dir = isset($argv[1]) ? $argv[1] : dirname(__FILE__);

function seg($marker, $data) {
    return "\xff".chr($marker).pack('n', strlen($data) + 2).$data;
}

// DQT table: Pq (0: 8-bit, 1: 16-bit), Tq, 64 values in zig-zag order
function dqt($pq, $tq, $base) {
    $t = chr(($pq << 4) | $tq);
    for ($k = 0; $k < 64; $k++) {
        $v = $base + $k;
        $t .= $pq ? pack('n', $v * 100) : chr($v);
    }
    return $t;
}

// DHT table: Tc (0: DC, 1: AC), Th, code counts L1..L16 and values
function dht($tc, $th, $counts) {
    $t = chr(($tc << 4) | $th);
    $v = '';
    $n = 0;
    for ($i = 0; $i < 16; $i++) {
        $li = isset($counts[$i]) ? $counts[$i] : 0;
        $t .= chr($li);
        for ($j = 0; $j < $li; $j++) {
            $v .= chr(($n++ * 7 + $tc * 0x10 + $th) & 0xff);
        }
    }
    return $t.$v;
}

// SOF: P, Y, X, components array(array(C, H, V, Tq), ...)
function sof($marker, $p, $y, $x, $comps) {
    $d = chr($p).pack('nn', $y, $x).chr(count($comps));
    foreach ($comps as $c) {
        $d .= chr($c[0]).chr(($c[1] << 4) | $c[2]).chr($c[3]);
    }
    return seg($marker, $d);
}

// SOS header: components array(array(Cs, Td, Ta), ...), Ss, Se, Ah, Al
function sos($comps, $ss, $se, $ah, $al) {
    $d = chr(count($comps));
    foreach ($comps as $c) {
        $d .= chr($c[0]).chr(($c[1] << 4) | $c[2]);
    }
    return seg(0xDA, $d.chr($ss).chr($se).chr(($ah << 4) | $al));
}

// dummy entropy-coded bytes with stuffed FF 00
function entropy($n, $seed) {
    $s = '';
    for ($i = 0; $i < $n; $i++) {
        $b = ($i * 31 + $seed * 17) & 0xff;
        $s .= ($b === 0xff) ? "\xff\x00" : chr($b);
    }
    return $s."\xff\x00".chr($seed);
}

$soi = "\xff\xd8";
$eoi = "\xff\xd9";
$files = array();

// baseline: JFIF 1.02 with 2x1 thumbnail, COM, two 8-bit DQT tables in one segment,
// SOF0 16x16 YCbCr 4:2:0, four Huffman tables in one DHT, 3-component SOS
$files['baseline.jpg'] = $soi.
    seg(0xE0, "JFIF\0\x01\x02\x01".pack('nn', 72, 72)."\x02\x01"."\xff\x00\x00\x00\xff\x00").
    seg(0xFE, "synthetic test image").
    seg(0xDB, dqt(0, 0, 1).dqt(0, 1, 2)).
    sof(0xC0, 8, 16, 16, array(array(1, 2, 2, 0), array(2, 1, 1, 1), array(3, 1, 1, 1))).
    seg(0xC4, dht(0, 0, array(0, 1, 5, 1, 1, 1, 1, 1, 1)).dht(1, 0, array(0, 2, 1, 3)).
              dht(0, 1, array(0, 3, 1, 1, 1, 1, 1, 1, 1, 1, 1)).dht(1, 1, array(0, 2, 1, 2, 4, 4, 3, 4, 7, 5, 4, 4, 0, 1, 2, 0x77))).
    sos(array(array(1, 0, 0), array(2, 1, 1), array(3, 1, 1)), 0, 63, 0, 0).
    entropy(40, 1).$eoi;

// Exif (APP1), ICC profile (APP2), Adobe (APP14), 16-bit DQT, SOF1 gray, DRI,
// RST0..RST7 then RST0 again, fill bytes before RST3 and EOI
$tiff = "MM\0\x2a\0\0\0\x08"."\0\x01"."\x01\x12\0\x03\0\0\0\x01\0\x01\0\0"."\0\0\0\0";
$icc = pack('N', 128).'test'."\x02\x10\0\0".'mntrGRAYXYZ '.str_repeat("\0", 12).'acsp'.str_repeat("\0", 88);
$scan = '';
for ($i = 0; $i < 9; $i++) {
    $scan .= entropy(5, $i + 2);
    $scan .= (($i % 8) === 3 ? "\xff\xff" : "\xff").chr(0xD0 + ($i % 8));
}
$files['restart.jpg'] = $soi.
    seg(0xE1, "Exif\0\0".$tiff).
    seg(0xE2, "ICC_PROFILE\0\x01\x01".$icc).
    seg(0xEE, "Adobe".pack('nnn', 100, 0x8000, 0x0001)."\x01").
    seg(0xDB, dqt(1, 0, 3)).
    sof(0xC1, 12, 8, 64, array(array(1, 1, 1, 0))).
    seg(0xC4, dht(0, 0, array(0, 1, 1)).dht(1, 0, array(0, 2))).
    seg(0xDD, pack('n', 1)).
    sos(array(array(1, 0, 0)), 0, 63, 0, 0).
    $scan.entropy(5, 11)."\xff\xff\xff".$eoi;

// progressive: JFXX extension APP0, SOF2, DHT between scans, successive approximation
$files['progressive.jpg'] = $soi.
    seg(0xE0, "JFXX\0\x10"."\xff\xd8\xff\xd9").
    seg(0xDB, dqt(0, 0, 4)).
    sof(0xC2, 8, 8, 8, array(array(1, 1, 1, 0))).
    seg(0xC4, dht(0, 0, array(0, 1, 1))).
    sos(array(array(1, 0, 0)), 0, 0, 0, 1).entropy(8, 21).
    seg(0xC4, dht(1, 0, array(0, 2, 1))).
    sos(array(array(1, 0, 0)), 1, 63, 0, 1).entropy(8, 22).
    sos(array(array(1, 0, 0)), 1, 63, 1, 0).entropy(8, 23).$eoi;

// CMYK: Adobe ColorTransform 2 (YCCK), SOF0 with component IDs 'C' 'M' 'Y' 'K'
$files['cmyk.jpg'] = $soi.
    seg(0xEE, "Adobe".pack('nnn', 100, 0x4000, 0)."\x02").
    seg(0xDB, dqt(0, 0, 5)).
    sof(0xC0, 8, 8, 8, array(array(67, 1, 1, 0), array(77, 1, 1, 0), array(89, 1, 1, 0), array(75, 1, 1, 0))).
    seg(0xC4, dht(0, 0, array(0, 1)).dht(1, 0, array(0, 1))).
    sos(array(array(67, 0, 0), array(77, 0, 0), array(89, 0, 0), array(75, 0, 0)), 0, 63, 0, 0).
    entropy(12, 31).$eoi;

// broken: bytes after EOI, cut inside the entropy data (no EOI), cut inside the DQT segment
$files['trailing.jpg'] = $files['baseline.jpg']."TRAILING";
$files['truncated.jpg'] = substr($files['baseline.jpg'], 0, -10);
$files['truncated_seg.jpg'] = substr($files['baseline.jpg'], 0, strpos($files['baseline.jpg'], "\xff\xdb") + 30);

foreach ($files as $name => $data) {
    file_put_contents($dir.'/'.$name, $data);
}
