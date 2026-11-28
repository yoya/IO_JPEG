<?php
/*
 * regression tests (no dependency)
 * usage: php tests/run.php
 *   IO_Bit is loaded from vendor/ (composer install) or include_path.
 */

$root = dirname(dirname(__FILE__));
if (is_readable($root.'/vendor/autoload.php')) {
    require_once $root.'/vendor/autoload.php';
}
set_include_path($root.PATH_SEPARATOR.get_include_path());
require_once 'IO/JPEG/Editor.php';

$failed = 0;
$passed = 0;

function check($name, $expect, $actual) {
    global $failed, $passed;
    if ($expect === $actual) {
        $passed++;
        return;
    }
    $failed++;
    echo "FAIL: $name".PHP_EOL;
    echo "  expect: ".var_export($expect, true).PHP_EOL;
    echo "  actual: ".var_export($actual, true).PHP_EOL;
}

function seg($marker, $data) {
    return "\xff".chr($marker).pack('n', strlen($data) + 2).$data;
}

// minimal synthetic JPEG (structure only)
function jpeg($opts = []) {
    $j = "\xff\xd8";
    $j .= seg(0xE0, "JFIF\0\x01\x01\x00\x00\x01\x00\x01\x00\x00");
    if (!empty($opts['com'])) {
        $j .= seg(0xFE, "hello comment");
    }
    if (!empty($opts['app1'])) {
        $j .= seg(0xE1, "Exif\0\0MM\0\x2a\0\0\0\x08\0\0");
    }
    if (!empty($opts['adobe'])) {
        $j .= seg(0xEE, "Adobe\x00\x64\x00\x00\x00\x00\x01");
    }
    $j .= seg(0xDB, "\x00".str_repeat("\x01", 64));
    $j .= seg(0xC0, "\x08\x00\x08\x00\x08\x01\x01\x11\x00");
    $j .= seg(0xC4, "\x00".str_repeat("\x00", 15)."\x01"."\x05");
    if (isset($opts['dri'])) {
        $j .= seg(0xDD, pack('n', $opts['dri']));
    }
    $j .= seg(0xDA, "\x01\x01\x12\x00\x3f\x00"); // Ns=1 Cs=1 Td=1 Ta=2
    $j .= "\x12\x34\xff\x00\x56";
    if (!empty($opts['rst'])) {
        $j .= "\xff\xd0\x78\x9a";
    }
    $j .= "\xff\xd9";
    return $j;
}

function parseJPEG($data, $class = 'IO_JPEG') {
    $jpeg = new $class();
    $jpeg->parse($data);
    return $jpeg;
}

function buildJPEG($jpeg) {
    ob_start();
    $jpeg->build();
    return ob_get_clean();
}

function dumpJPEG($jpeg, $opts) {
    ob_start();
    $jpeg->dump($opts);
    return ob_get_clean();
}

// run a sample tool on $data (written to a temporary file), return stdout
function runSample($script, $data, $args = '') {
    global $root;
    $file = tempnam(sys_get_temp_dir(), 'iojpeg');
    file_put_contents($file, $data);
    $cmd = escapeshellarg(PHP_BINARY).' -d include_path='.escapeshellarg(get_include_path()).
         ' '.escapeshellarg($root.'/sample/'.$script).' -f '.escapeshellarg($file).' '.$args.' 2>/dev/null';
    $out = shell_exec($cmd);
    unlink($file);
    return (string) $out;
}

// J1: rebuild() must keep segments that _buildChunkDetail() can not rebuild
$in = jpeg(['com' => true, 'app1' => true]);
$jpeg = parseJPEG($in, 'IO_JPEG_Editor');
$jpeg->rebuild();
check('rebuild keeps COM/APP1', bin2hex($in), bin2hex(buildJPEG($jpeg)));

function comChunk($text) {
    $chunk = new IO_JPEG_Chunk();
    $chunk->marker = 0xFE;
    $chunk->data = $text;
    return $chunk;
}

// J2: updateOrAppendChunkBetween() must insert the chunk object itself
$jpeg = parseJPEG(jpeg(), 'IO_JPEG_Editor');
check('insert after SOI returns', true,
      $jpeg->updateOrAppendChunkBetween(comChunk("A"), 0xD8, null));
check('insert after SOI position', 0xFE, $jpeg->_jpegChunk[1]->marker);
$jpeg = parseJPEG(jpeg(), 'IO_JPEG_Editor');
$jpeg->updateOrAppendChunkBetween(comChunk("B"), null, 0xDB);
$out = buildJPEG($jpeg);
$dqtPos = strpos(jpeg(), "\xff\xdb");
$expect = substr(jpeg(), 0, $dqtPos).seg(0xFE, "B").substr(jpeg(), $dqtPos);
check('insert before DQT build', bin2hex($expect), bin2hex($out));

// J3: with both anchors, insert after the first and before the second
$jpeg = parseJPEG(jpeg(), 'IO_JPEG_Editor');
check('insert between SOI and DQT returns', true,
      $jpeg->updateOrAppendChunkBetween(comChunk("C"), 0xD8, 0xDB));
$dqtPos = strpos(jpeg(), "\xff\xdb");
$expect = substr(jpeg(), 0, $dqtPos).seg(0xFE, "C").substr(jpeg(), $dqtPos);
check('insert between SOI and DQT build', bin2hex($expect), bin2hex(buildJPEG($jpeg)));

// J3: removeChunk() must not leave holes that shift later inserts
$jpeg = parseJPEG(jpeg(['com' => true]), 'IO_JPEG_Editor');
$jpeg->removeChunk(0xFE);
$jpeg->updateOrAppendChunkBetween(comChunk("D"), null, 0xDB);
$dqtPos = strpos(jpeg(), "\xff\xdb");
$expect = substr(jpeg(), 0, $dqtPos).seg(0xFE, "D").substr(jpeg(), $dqtPos);
check('insert after removeChunk build', bin2hex($expect), bin2hex(buildJPEG($jpeg)));

// J4: jpegcolor.php -a must write an "Adobe" APP14 marker
$out = runSample('jpegcolor.php', jpeg(), '-a 2');
$dqtPos = strpos(jpeg(), "\xff\xdb");
$expect = substr(jpeg(), 0, $dqtPos).seg(0xEE, "Adobe\x00\x64\x40\x00\x00\x00\x02").substr(jpeg(), $dqtPos);
check('jpegcolor -a 2', bin2hex($expect), bin2hex($out));

// J5: SOS detail dump must not swap Td and Ta
$out = dumpJPEG(parseJPEG(jpeg()), ['detail' => true]);
check('SOS dump Td/Ta', 1, substr_count($out, "Cs:1 Td:1 Ta:2"));

// J6: APP14 detail dump must print the identifier string
$out = dumpJPEG(parseJPEG(jpeg(['adobe' => true])), ['detail' => true]);
check('APP14 dump ID', 1, substr_count($out, "ID:Adobe Version:100"));

// J7: hexdump of a chunk without length field must not spill into the next one
$out = dumpJPEG(parseJPEG("\xff\xd8\xff\xd0\x78\x9a\xff\xd9"), ['hexdump' => true]);
preg_match('/^RST0:.*\n.*\n(.*)\n/m', $out, $m);
check('RST hexdump bytes', 'ff d0 78 9a', isset($m[1]) ? trim(substr($m[1], 10, 50)) : null);

// J9: unknown marker gets a hex name instead of an undefined-key warning
$in = jpeg();
$in = substr($in, 0, 2).seg(0xB0, "abc").substr($in, 2);
$out = dumpJPEG(parseJPEG($in), []);
check('unknown marker dump name', 1, substr_count($out, "\n0xB0: length=5\n"));

// J9: JPGn markers are F0..FD, so F7 is JPG7
$chunk = new IO_JPEG_Chunk();
check('marker name 0xF7', 'JPG7', $chunk->get_marker_name(0xF7));
check('marker name 0xFD', 'JPG13', $chunk->get_marker_name(0xFD));

// J10: sample tools must not add a length field to RST/DRI segments
$in = jpeg(['dri' => 16, 'rst' => true]);
check('jpegdelmeta RST/DRI', bin2hex($in), bin2hex(runSample('jpegdelmeta.php', $in)));
check('jpegrepair RST/DRI', bin2hex($in), bin2hex(runSample('jpegrepair.php', $in)));
$dir = sys_get_temp_dir().'/iojpeg_chunk_'.getmypid();
mkdir($dir);
$cwd = getcwd();
chdir($dir);
runSample('jpegchunk.php', $in, '-s -c');
chdir($cwd);
$files = glob("$dir/*.jpg");
sort($files);
check('jpegchunk -s -c RST/DRI', bin2hex($in), bin2hex(file_get_contents(end($files))));
array_map('unlink', $files);
rmdir($dir);

// J8: DRI is a length-prefixed segment (Ri = 0x00FF looked like a marker)
$in = jpeg(['dri' => 0x00FF, 'rst' => true]);
$jpeg = parseJPEG($in);
$markers = [];
foreach ($jpeg->_jpegChunk as $chunk) {
    $markers []= $chunk->marker;
}
check('DRI Ri=0x00FF chunks', [0xD8, 0xE0, 0xDB, 0xC0, 0xC4, 0xDD, 0xDA, 0xD0, 0xD9], $markers);
$dri = $jpeg->_jpegChunk[5];
check('DRI data/length', ["\x00\xff", 4], [$dri->data, $dri->length]);
$dri->_parseChunkDetail();
check('DRI Lr/Ri', [4, 255], [$dri->Lr, $dri->Ri]);
check('DRI build', bin2hex($in), bin2hex(buildJPEG($jpeg)));
$jpeg = parseJPEG($in, 'IO_JPEG_Editor');
$jpeg->rebuild();
check('DRI rebuild', bin2hex($in), bin2hex(buildJPEG($jpeg)));
check('DRI dump', 1, substr_count(dumpJPEG(parseJPEG($in), []), "\nDRI: length=4\n"));
check('jpegdelmeta DRI Ri=0x00FF', bin2hex($in), bin2hex(runSample('jpegdelmeta.php', $in)));

// J9: fill bytes (FF ...) before a marker are accepted and kept
$in = jpeg(['rst' => true]);
$in = str_replace(["\xff\xdb", "\xff\xd0", "\xff\xd9"], ["\xff\xff\xff\xdb", "\xff\xff\xd0", "\xff\xff\xff\xff\xd9"], $in);
$jpeg = parseJPEG($in);
$fills = [];
foreach ($jpeg->_jpegChunk as $chunk) {
    $fills[$chunk->get_marker_name()] = $chunk->fillLength;
}
check('fill bytes chunks', ['SOI' => 0, 'APP0' => 0, 'DQT' => 2, 'SOF0' => 0, 'DHT' => 0, 'SOS' => 0, 'RST0' => 1, 'EOI' => 3], $fills);
check('fill bytes SOS data', "\x00\x08\x01\x01\x12\x00\x3f\x00\x12\x34\xff\x00\x56", $jpeg->_jpegChunk[5]->data);
check('fill bytes build', bin2hex($in), bin2hex(buildJPEG($jpeg)));
$out = dumpJPEG($jpeg, ['hexdump' => true]);
check('fill bytes dump', 1, substr_count($out, "\nDQT: length=67 fill=2\n"));
preg_match('/^EOI: fill=3\n.*\n(.*)\n/m', $out, $m);
check('fill bytes hexdump', 'ff ff ff ff d9', isset($m[1]) ? trim(substr($m[1], 10, 50)) : null);
$chunk = new IO_JPEG_Chunk();
check('new chunk fillLength', 0, $chunk->fillLength);

// J11: jpegcat.php must run on PHP 8 (no curly-brace string offset)
$parts = ["\xff\xd8", seg(0xFE, "cat"), "\xff\xd9"];
$files = [];
foreach ($parts as $part) {
    $file = tempnam(sys_get_temp_dir(), 'iojpeg');
    file_put_contents($file, $part);
    $files []= $file;
}
$cmd = escapeshellarg(PHP_BINARY).' -d include_path='.escapeshellarg(get_include_path()).
     ' '.escapeshellarg($root.'/sample/jpegcat.php').' '.join(' ', array_map('escapeshellarg', $files)).' 2>/dev/null';
$out = (string) shell_exec($cmd);
array_map('unlink', $files);
check('jpegcat', bin2hex(join('', $parts)), bin2hex($out));

// sample tools keep fill bytes before markers
$in = jpeg(['dri' => 16, 'rst' => true]);
$in = str_replace(["\xff\xdb", "\xff\xdd", "\xff\xd0", "\xff\xd9"], ["\xff\xff\xff\xdb", "\xff\xff\xdd", "\xff\xff\xd0", "\xff\xff\xff\xd9"], $in);
check('jpegdelmeta fill bytes', bin2hex($in), bin2hex(runSample('jpegdelmeta.php', $in)));
check('jpegrepair fill bytes', bin2hex($in), bin2hex(runSample('jpegrepair.php', $in)));
foreach (['-s -c' => 'jpg', '-s' => 'jc'] as $args => $ext) {
    $dir = sys_get_temp_dir().'/iojpeg_chunk_'.getmypid();
    mkdir($dir);
    $cwd = getcwd();
    chdir($dir);
    runSample('jpegchunk.php', $in, $args);
    chdir($cwd);
    $files = glob("$dir/*.$ext");
    sort($files);
    if ($ext === 'jpg') {
        check('jpegchunk -s -c fill bytes', bin2hex($in), bin2hex(file_get_contents(end($files))));
    } else {
        $cmd = escapeshellarg(PHP_BINARY).' -d include_path='.escapeshellarg(get_include_path()).
             ' '.escapeshellarg($root.'/sample/jpegcat.php').' '.join(' ', array_map('escapeshellarg', $files)).' 2>/dev/null';
        check('jpegchunk -s + jpegcat fill bytes', bin2hex($in), bin2hex((string) shell_exec($cmd)));
    }
    array_map('unlink', $files);
    rmdir($dir);
}

/*
 * test data (tests/data/make.php)
 */
function checkThrows($name, $func) {
    global $failed, $passed;
    try {
        $func();
    } catch (Exception $e) {
        $passed++;
        return;
    }
    $failed++;
    echo "FAIL: $name (exception not thrown)".PHP_EOL;
}

// run a php script in a child process (same include_path, E_ALL), killed after $timeout seconds
function runPHP($args, $cwd = null, $timeout = 10) {
    $cmd = escapeshellarg(PHP_BINARY).' -d include_path='.escapeshellarg(get_include_path()).
         ' -d error_reporting=-1 -d display_errors=stderr '.join(' ', array_map('escapeshellarg', $args));
    $proc = proc_open($cmd, array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $cwd);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $out = $err = '';
    $deadline = microtime(true) + $timeout;
    while (true) {
        $out .= stream_get_contents($pipes[1]);
        $err .= stream_get_contents($pipes[2]);
        $st = proc_get_status($proc);
        if (! $st['running']) {
            break;
        }
        if (microtime(true) > $deadline) {
            proc_terminate($proc, 9);
            $err .= 'TIMEOUT';
            break;
        }
        usleep(10000);
    }
    $out .= stream_get_contents($pipes[1]);
    $err .= stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    return array($st['running'] ? -1 : $st['exitcode'], $out, $err);
}

$dataDir = $root.'/tests/data';
function dataFile($name) {
    global $dataDir;
    return file_get_contents($dataDir.'/'.$name);
}
function comChunk2($marker, $data) {
    $chunk = new IO_JPEG_Chunk();
    $chunk->marker = $marker;
    $chunk->data = $data;
    return $chunk;
}
function markers($jpeg) {
    return array_map(function ($c) { return $c->get_marker_name(); }, $jpeg->_jpegChunk);
}
function chunkOf($jpeg, $name, $nth = 0) {
    foreach ($jpeg->_jpegChunk as $c) {
        if ($c->get_marker_name() === $name && $nth-- === 0) {
            $c->_parseChunkDetail();
            return $c;
        }
    }
    return null;
}
// remove the length-prefixed segment with $marker from $data
function withoutSeg($data, $marker) {
    $pos = strpos($data, "\xff".chr($marker));
    $len = unpack('n', substr($data, $pos + 2, 2))[1];
    return substr($data, 0, $pos).substr($data, $pos + 2 + $len);
}
// call $func and collect PHP warnings / notices it raises (output is discarded)
function errorsOf($func) {
    $errors = array();
    set_error_handler(function ($no, $str) use (&$errors) { $errors []= $str; return true; });
    ob_start();
    try {
        $func();
    } finally {
        ob_end_clean();
        restore_error_handler();
    }
    return $errors;
}
$tmpDir = sys_get_temp_dir().'/io_jpeg_test_'.getmypid();
mkdir($tmpDir);
function cleanTmp() {
    global $tmpDir;
    foreach (glob("$tmpDir/*") as $f) {
        unlink($f);
    }
}

// make.php regenerates the committed files byte-identically
$dataNames = array('baseline.jpg', 'restart.jpg', 'progressive.jpg', 'cmyk.jpg', 'trailing.jpg', 'truncated.jpg', 'truncated_seg.jpg');
list($status, $out, $err) = runPHP(array($dataDir.'/make.php', $tmpDir));
check('make.php status', array(0, ''), array($status, $err));
foreach ($dataNames as $name) {
    check("make.php $name", md5(dataFile($name)), md5((string) @file_get_contents("$tmpDir/$name")));
}
cleanTmp();
$good = array('baseline.jpg', 'restart.jpg', 'progressive.jpg', 'cmyk.jpg');

// baseline.jpg
$data = dataFile('baseline.jpg');
$jpeg = parseJPEG($data);
check('baseline markers', array('SOI', 'APP0', 'COM', 'DQT', 'SOF0', 'DHT', 'SOS', 'EOI'), markers($jpeg));
$c = chunkOf($jpeg, 'APP0');
check('baseline APP0 segment', array(2, 22), array($c->startOffset, $c->length));
check('baseline APP0 JFIF', array("JFIF\0", '1.02', 1, 72, 72, 2, 1, "\xff\x00\x00\x00\xff\x00"),
      array($c->identifier, $c->version, $c->U, $c->Xd, $c->Yd, $c->Xt, $c->Yt, $c->RGB));
check('baseline COM', array(22, 'synthetic test image'), array(chunkOf($jpeg, 'COM')->length, chunkOf($jpeg, 'COM')->data));
$c = chunkOf($jpeg, 'DQT');
check('baseline DQT', array(132, array(0, 0), array(0, 1), range(1, 64), range(2, 65)), array($c->length, $c->Pq, $c->Tq, $c->Q[0], $c->Q[1]));
$c = chunkOf($jpeg, 'SOF0');
check('baseline SOF0', array(17, 8, 16, 16, 3, array(1, 2, 3), array(2, 1, 1), array(2, 1, 1), array(0, 1, 1)),
      array($c->length, $c->P, $c->Y, $c->X, $c->Nf, $c->C, $c->H, $c->V, $c->Tq));
$c = chunkOf($jpeg, 'DHT');
check('baseline DHT classes', array(array(0, 1, 0, 1), array(0, 0, 1, 1)), array($c->Tc, $c->Th));
check('baseline DHT L', array(array(0, 1, 5, 1, 1, 1, 1, 1, 1, 0, 0, 0, 0, 0, 0, 0), array(0, 2, 1, 3, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0)),
      array($c->L[0], $c->L[1]));
check('baseline DHT V', array(array(1 => array(0x00), 2 => array(0x07, 0x0e, 0x15, 0x1c, 0x23)), array(0x10, 0x17)),
      array(array_slice($c->V[0], 0, 2, true), $c->V[1][1]));
check('baseline DHT AC1 162 codes', array(162, 119), array(array_sum($c->L[3]), count($c->V[3][15])));
$c = chunkOf($jpeg, 'SOS');
$sosPos = strpos($data, "\xff\xda");
check('baseline SOS header', array(null, 12, 3, array(1, 2, 3), array(0, 1, 1), array(0, 1, 1), 0, 63, 0, 0),
      array($c->length, $c->Ls, $c->Ns, $c->Cs, $c->Td, $c->Ta, $c->Ss, $c->Se, $c->Ah, $c->Al));
check('baseline SOS entropy data', array(43, substr($data, $sosPos + 2 + 12, -2)), array(strlen($c->huffmanData), $c->huffmanData));
check('baseline SOS data', substr($data, $sosPos + 2, -2), $c->data);
check('baseline EOI', array(null, 0, strlen($data) - 2), array(chunkOf($jpeg, 'EOI')->data, chunkOf($jpeg, 'EOI')->fillLength, chunkOf($jpeg, 'EOI')->startOffset));

// restart.jpg: APP1 Exif, APP2 ICC, APP14, 16-bit DQT, SOF1, DRI, RST0..7 + RST0, fill bytes
$data = dataFile('restart.jpg');
$jpeg = parseJPEG($data);
check('restart markers', array('SOI', 'APP1', 'APP2', 'APP14', 'DQT', 'SOF1', 'DHT', 'DRI', 'SOS',
                               'RST0', 'RST1', 'RST2', 'RST3', 'RST4', 'RST5', 'RST6', 'RST7', 'RST0', 'EOI'), markers($jpeg));
check('restart APP1 Exif', array(34, "Exif\0\0MM\0\x2a\0\0\0\x08"), array(chunkOf($jpeg, 'APP1')->length, substr(chunkOf($jpeg, 'APP1')->data, 0, 14)));
$c = chunkOf($jpeg, 'APP2');
check('restart APP2 ICC', array(144, "ICC_PROFILE\0\x01\x01", 128, 'acsp'),
      array($c->length, substr($c->data, 0, 14), unpack('N', substr($c->data, 14, 4))[1], substr($c->data, 14 + 36, 4)));
$c = chunkOf($jpeg, 'APP14');
check('restart APP14', array('Adobe', 100, 0x8000, 0x0001, 1), array($c->ID, $c->Version, $c->Flag0, $c->Flag1, $c->ColorTransform));
$c = chunkOf($jpeg, 'DQT');
check('restart DQT 16-bit', array(131, array(1), array(0), 300, 6600, 64), array($c->length, $c->Pq, $c->Tq, $c->Q[0][0], $c->Q[0][63], count($c->Q[0])));
$c = chunkOf($jpeg, 'SOF1');
check('restart SOF1', array(12, 8, 64, 1, array(1)), array($c->P, $c->Y, $c->X, $c->Nf, $c->C));
$c = chunkOf($jpeg, 'DRI');
check('restart DRI', array("\x00\x01", 4, 4, 1), array($c->data, $c->length, $c->Lr, $c->Ri));
$fills = array();
$lens = array();
foreach ($jpeg->_jpegChunk as $c) {
    $fills []= $c->fillLength;
    if (strncmp($c->get_marker_name(), 'RST', 3) === 0) {
        $c->_parseChunkDetail();
        $lens []= strlen($c->huffmanData);
    }
}
check('restart fill bytes', array(0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 0, 0, 0, 0, 0, 3), $fills);
check('restart RST data sizes', array_fill(0, 9, 8), $lens);
check('restart SOS entropy', 8, strlen(chunkOf($jpeg, 'SOS')->huffmanData));
$rst3 = chunkOf($jpeg, 'RST3');
check('restart RST3 offset', array(strpos($data, "\xff\xff\xd3"), $rst3->data), array($rst3->startOffset, substr($data, $rst3->startOffset + 3, 8)));

// progressive.jpg: JFXX, SOF2, three scans, DHT between scans
$jpeg = parseJPEG(dataFile('progressive.jpg'));
check('progressive markers', array('SOI', 'APP0', 'DQT', 'SOF2', 'DHT', 'SOS', 'DHT', 'SOS', 'SOS', 'EOI'), markers($jpeg));
$c = chunkOf($jpeg, 'APP0');
check('progressive JFXX', array("JFXX\0", 0x10, "\xff\xd8\xff\xd9"), array($c->identifier, $c->extension_code, $c->extension_data));
$scans = array();
for ($i = 0; $i < 3; $i++) {
    $c = chunkOf($jpeg, 'SOS', $i);
    $scans []= array($c->Ss, $c->Se, $c->Ah, $c->Al);
}
check('progressive scans', array(array(0, 0, 0, 1), array(1, 63, 0, 1), array(1, 63, 1, 0)), $scans);
check('progressive DHT 2', array(array(1), array(0)), array(chunkOf($jpeg, 'DHT', 1)->Tc, chunkOf($jpeg, 'DHT', 1)->Th));

// cmyk.jpg
$jpeg = parseJPEG(dataFile('cmyk.jpg'));
check('cmyk markers', array('SOI', 'APP14', 'DQT', 'SOF0', 'DHT', 'SOS', 'EOI'), markers($jpeg));
check('cmyk APP14 ColorTransform', 2, chunkOf($jpeg, 'APP14')->ColorTransform);
check('cmyk SOF0 components', array(4, array(67, 77, 89, 75)), array(chunkOf($jpeg, 'SOF0')->Nf, chunkOf($jpeg, 'SOF0')->C));
check('cmyk SOS components', array(4, array(67, 77, 89, 75)), array(chunkOf($jpeg, 'SOS')->Ns, chunkOf($jpeg, 'SOS')->Cs));

// trailing.jpg: parse stops at EOI
$jpeg = parseJPEG(dataFile('trailing.jpg'));
check('trailing markers', array('SOI', 'APP0', 'COM', 'DQT', 'SOF0', 'DHT', 'SOS', 'EOI'), markers($jpeg));
check('trailing build', bin2hex(dataFile('baseline.jpg')), bin2hex(buildJPEG($jpeg)));

// build / rebuild round trips, also with sosScan = false and eoiFinish = false
foreach ($good as $name) {
    $data = dataFile($name);
    check("build $name", bin2hex($data), bin2hex(buildJPEG(parseJPEG($data))));
    $jpeg = parseJPEG($data, 'IO_JPEG_Editor');
    $jpeg->rebuild();
    check("rebuild $name", bin2hex($data), bin2hex(buildJPEG($jpeg)));
    $jpeg = new IO_JPEG();
    $jpeg->parse($data, false, false);
    check("build $name (no SOS scan)", bin2hex($data), bin2hex(buildJPEG($jpeg)));
    $jpeg = new IO_JPEG_Editor();
    $jpeg->parse($data, false, false);
    $jpeg->rebuild();
    check("rebuild $name (no SOS scan)", bin2hex($data), bin2hex(buildJPEG($jpeg)));
}
$jpeg = new IO_JPEG();
$jpeg->parse(dataFile('restart.jpg'), true, false);
check('restart no SOS scan markers', array('SOI', 'APP1', 'APP2', 'APP14', 'DQT', 'SOF1', 'DHT', 'DRI', 'SOS', 'EOI'), markers($jpeg));

// edit APIs (IO_JPEG_Editor)
$data = dataFile('baseline.jpg');
$jpeg = parseJPEG($data, 'IO_JPEG_Editor');
$jpeg->removeChunk(0xFE);
check('removeChunk COM', bin2hex(withoutSeg($data, 0xFE)), bin2hex(buildJPEG($jpeg)));
$jpeg = parseJPEG($data, 'IO_JPEG_Editor');
check('update COM returns', true, $jpeg->updateOrAppendChunkBetween(comChunk('new'), 0xD8, null));
$comPos = strpos($data, "\xff\xfe");
check('update COM build', bin2hex(substr($data, 0, $comPos).seg(0xFE, 'new').substr($data, $comPos + 24)), bin2hex(buildJPEG($jpeg)));
check('insert anchors not found', false, $jpeg->updateOrAppendChunkBetween(comChunk2(0xEF, 'x'), 0xB0, null));
checkThrows('insert without anchors', function () use ($jpeg) { $jpeg->updateOrAppendChunkBetween(comChunk('x'), null, null); });
$jpeg = parseJPEG($data, 'IO_JPEG_Editor');
$jpeg->rebuild();
$sof = chunkOf($jpeg, 'SOF0');
$sof->X = 0x1234;
$sof->Y = 0x0567;
$dqt = chunkOf($jpeg, 'DQT');
$dqt->Q[1][63] = 0xEE;
$app0 = chunkOf($jpeg, 'APP0');
$app0->version = '1.01';
$app0->Xd = 300;
$out = buildJPEG($jpeg);
$sofPos = strpos($data, "\xff\xc0");
$dqtPos = strpos($data, "\xff\xdb");
$expect = $data;
$expect = substr_replace($expect, "\x05\x67\x12\x34", $sofPos + 5, 4);
$expect = substr_replace($expect, "\xee", $dqtPos + 4 + 65 + 64, 1);
$expect = substr_replace($expect, "\x01\x01\x01\x01\x2c", 2 + 4 + 5, 5);
check('rebuild with edited SOF/DQT/APP0', bin2hex($expect), bin2hex($out));
$jpeg = parseJPEG(dataFile('restart.jpg'), 'IO_JPEG_Editor');
$jpeg->rebuild();
chunkOf($jpeg, 'DRI')->Ri = 0x0102;
chunkOf($jpeg, 'APP14')->ColorTransform = 0;
chunkOf($jpeg, 'SOS')->Ss = 5;
$out = parseJPEG(buildJPEG($jpeg));
check('rebuild with edited DRI/APP14/SOS', array(0x0102, 0, 5),
      array(chunkOf($out, 'DRI')->Ri, chunkOf($out, 'APP14')->ColorTransform, chunkOf($out, 'SOS')->Ss));
$jpeg = parseJPEG(dataFile('cmyk.jpg'), 'IO_JPEG_Editor');
$adobe = new IO_JPEG_Chunk();
$adobe->marker = 0xEE;
$adobe->ID = 'Adobe';
$adobe->Version = 101;
$adobe->Flag0 = 0;
$adobe->Flag1 = 0;
$adobe->ColorTransform = 0;
$jpeg->updateOrAppendChunkBetween($adobe, null, 0xDB);
check('replace APP14 from fields', bin2hex("\xff\xd8".seg(0xEE, "Adobe\x00\x65\0\0\0\0\0").substr(dataFile('cmyk.jpg'), 18)), bin2hex(buildJPEG($jpeg)));

// dump(): no warnings (summary, detail, hexdump)
foreach ($good as $name) {
    $jpeg = parseJPEG(dataFile($name));
    check("dump $name", array(), errorsOf(function () use ($jpeg) { $jpeg->dump(array()); }));
    check("dump detail hexdump $name", array(), errorsOf(function () use ($jpeg) { $jpeg->dump(array('detail' => true, 'hexdump' => true)); }));
}
$out = dumpJPEG(parseJPEG(dataFile('restart.jpg')), array('detail' => true));
check('dump restart APP14', true, strpos($out, "APP14: length=14\n\tID:Adobe Version:100 Flag0:0x8000 Flag1:0x0001 ColorTransform:1(YCbCr)\n") !== false);
check('dump restart DRI', true, strpos($out, "DRI: length=4\n\tLr:4 Ri:1\n") !== false);
check('dump restart RST3 fill', true, strpos($out, "\nRST3: length=(null) fill=1\n") !== false);
check('dump restart EOI fill', true, strpos($out, "\nEOI: fill=3\n\tEnd Of Image\n") !== false);
check('dump restart DQT 16-bit', true, strpos($out, "\tPq:1(16-bit) Tq:0\n\tQ[k=0x00]:012c 0190 ") !== false);
$out = dumpJPEG(parseJPEG(dataFile('baseline.jpg')), array('detail' => true));
check('dump baseline APP0', true, strpos($out, "\tverison:1.02\n\tU:1 Xd:72 Yd:72 Xt:2 Yt:1 RGB(size:6)\n") !== false);
check('dump baseline SOF0', true, strpos($out, "\t(Baseline DCT)\n\tP:8 Y:16 X:16 Nf:3\n\t[i=1]: C:1 H:2 V:2 Tq:0\n") !== false);
check('dump baseline DHT', true, strpos($out, "\tTc:1(AC) Th:0\n\tLi:0 2 1 3 0 0 0 0 0 0 0 0 0 0 0 0\n") !== false);
check('dump baseline SOS', true, strpos($out, "\tSs:0 Se:63 Ah:0 Al:0\n\t(Huffman Encoded Data len:43)\n") !== false);
$out = dumpJPEG(parseJPEG(dataFile('progressive.jpg')), array('detail' => true));
check('dump progressive SOF2', true, strpos($out, "SOF2: length=11\n\t(Progressive DCT, Huffman coding)\n") !== false);

// broken input
checkThrows('parse truncated entropy data', function () { parseJPEG(dataFile('truncated.jpg')); });
checkThrows('parse truncated segment', function () { parseJPEG(dataFile('truncated_seg.jpg')); });
checkThrows('parse truncated length field', function () { parseJPEG("\xff\xd8\xff\xe0\x00"); });
checkThrows('dump without chunks', function () { parseJPEG('')->dump(array()); });
checkThrows('build without chunks', function () { buildJPEG(parseJPEG('')); });
function detailOf($marker, $data) {
    $jpeg = parseJPEG("\xff\xd8".seg($marker, $data)."\xff\xd9");
    $jpeg->_jpegChunk[1]->_parseChunkDetail();
}
checkThrows('SOF0 short component list', function () { detailOf(0xC0, "\x08\x00\x08\x00\x08\x03\x01\x11\x00"); });
checkThrows('DHT short values', function () { detailOf(0xC4, "\x00".str_repeat("\x00", 15)."\x05\x01\x02"); });
checkThrows('DQT short 16-bit table', function () { detailOf(0xDB, "\x10".str_repeat("\x01", 100)); });
checkThrows('APP0 short JFIF', function () { detailOf(0xE0, "JFIF\0\x01\x02"); });
checkThrows('APP14 short', function () { detailOf(0xEE, "Adobe\x00\x64"); });
checkThrows('DRI short', function () { detailOf(0xDD, ""); });

/*
 * sample/*.php on the test data
 */
function sample($script, $args, $cwd = null) {
    global $root;
    return runPHP(array_merge(array($root.'/sample/'.$script), $args), $cwd);
}
foreach ($good as $name) {
    $f = "$dataDir/$name";
    foreach (array(array(), array('-D'), array('-h'), array('-S')) as $opt) {
        list($status, $out, $err) = sample('jpegdump.php', array_merge($opt, array('-f', $f)));
        check("jpegdump ".join(' ', $opt)." $name", array(0, '', 1), array($status, $err, preg_match('/^SOI:\n.*\nEOI:/s', $out)));
    }
    list($status, $out, $err) = sample('jpegrebuild.php', array($f));
    check("jpegrebuild $name", array(0, '', md5(dataFile($name))), array($status, $err, md5($out)));
    list($status, $out, $err) = sample('jpegrepair.php', array('-f', $f));
    check("jpegrepair $name", array(0, '', md5(dataFile($name))), array($status, $err, md5($out)));
    list($status, $out, $err) = sample('jpegcolor.php', array('-f', $f));
    check("jpegcolor $name", array(0, '', 1), array($status, $err, preg_match('/\tComponentID: /', $out)));
}
list($status, $out, $err) = sample('jpegdump.php', array('-f', "$dataDir/trailing.jpg"));
check('jpegdump trailing', array(0, 8, 1), array($status, substr_count($err, 'WARNING: parse marker1='), preg_match('/\nEOI:\n\tEnd Of Image\n$/', $out)));
list($status, $out, $err) = sample('jpegdump.php', array('-f', "$dataDir/truncated.jpg"));
check('jpegdump truncated', array(0, '', 1), array($status, $err, preg_match('/^Exception/', $out)));

// jpegdelmeta: keeps SOI, APP0, DQT, SOFn, DHT, DRI, SOS, RSTn, EOI
$tests = array('baseline.jpg' => array(0xFE), 'restart.jpg' => array(0xE1, 0xE2, 0xEE), 'progressive.jpg' => array(), 'cmyk.jpg' => array(0xEE));
foreach ($tests as $name => $removed) {
    $expect = dataFile($name);
    foreach ($removed as $m) {
        $expect = withoutSeg($expect, $m);
    }
    list($status, $out, $err) = sample('jpegdelmeta.php', array('-f', "$dataDir/$name"));
    check("jpegdelmeta $name", array(0, '', bin2hex($expect)), array($status, $err, bin2hex($out)));
}

// jpegchunk -s / -s -c / -S, jpegcat
list($status, $out, $err) = sample('jpegchunk.php', array('-s', '-f', "$dataDir/restart.jpg"), $tmpDir);
$files = glob("$tmpDir/*.jc");
check('jpegchunk -s', array(0, '', 19, '00_SOI.jc', '12_RST3.jc', '18_EOI.jc'),
      array($status, $err, count($files), basename($files[0]), basename($files[12]), basename($files[18])));
check('jpegchunk -s RST3 bytes', "\xff\xff\xd3".substr(dataFile('restart.jpg'), strpos(dataFile('restart.jpg'), "\xff\xff\xd3") + 3, 8), file_get_contents($files[12]));
list($status, $out, $err) = sample('jpegcat.php', $files);
check('jpegcat restart', array(0, '', md5(dataFile('restart.jpg'))), array($status, $err, md5($out)));
cleanTmp();
list($status, $out, $err) = sample('jpegchunk.php', array('-s', '-c', '-f', "$dataDir/progressive.jpg"), $tmpDir);
$files = glob("$tmpDir/*.jpg");
check('jpegchunk -s -c', array(0, '', 10, md5(dataFile('progressive.jpg')), "\xff\xd8".seg(0xE0, "JFXX\0\x10\xff\xd8\xff\xd9")),
      array($status, $err, count($files), md5(file_get_contents(end($files))), file_get_contents($files[1])));
cleanTmp();
list($status, $out, $err) = sample('jpegchunk.php', array('-s', '-S', '-f', "$dataDir/restart.jpg"), $tmpDir);
$files = array_map('basename', glob("$tmpDir/*.jc"));
check('jpegchunk -s -S', array(0, '', array('00_SOI.jc', '01_APP1.jc', '02_APP2.jc', '03_APP14.jc', '04_DQT.jc', '05_SOF1.jc', '06_DHT.jc', '07_DRI.jc', '08_SOS.jc', '09_EOI.jc')),
      array($status, $err, $files));
cleanTmp();

// jpegcolor
list($status, $out, $err) = sample('jpegcolor.php', array('-f', "$dataDir/cmyk.jpg"));
check('jpegcolor cmyk dump', array(0, '', 1, 1), array($status, $err,
      preg_match('/ColorTransform:2\(YCCK\)\n/', $out), preg_match('/\tComponentID: 67\(C\) 77\(M\) 89\(Y\) 75\(K\) \n/', $out)));
$cmyk = dataFile('cmyk.jpg');
list($status, $out, $err) = sample('jpegcolor.php', array('-f', "$dataDir/cmyk.jpg", '-A'));
check('jpegcolor -A', array(0, '', bin2hex(withoutSeg($cmyk, 0xEE))), array($status, $err, bin2hex($out)));
list($status, $out, $err) = sample('jpegcolor.php', array('-f', "$dataDir/cmyk.jpg", '-j'));
check('jpegcolor -j', array(0, '', bin2hex("\xff\xd8".seg(0xE0, "JFIF\0\x01\x01\x00\x00\x01\x00\x01\x00\x00").substr($cmyk, 2))), array($status, $err, bin2hex($out)));
list($status, $out, $err) = sample('jpegcolor.php', array('-f', "$dataDir/baseline.jpg", '-J'));
check('jpegcolor -J', array(0, '', bin2hex(withoutSeg(dataFile('baseline.jpg'), 0xE0))), array($status, $err, bin2hex($out)));
list($status, $out, $err) = sample('jpegcolor.php', array('-f', "$dataDir/cmyk.jpg", '-c', '1,2,3,4'));
$out = parseJPEG($out);
check('jpegcolor -c', array(0, '', array(1, 2, 3, 4), array(1, 2, 3, 4)), array($status, $err, chunkOf($out, 'SOF0')->C, chunkOf($out, 'SOS')->Cs));
list($status, $out, $err) = sample('jpegcolor.php', array('-f', "$dataDir/restart.jpg", '-a', '0'));
$out = parseJPEG($out);
$c = chunkOf($out, 'APP14');
check('jpegcolor -a 0 replaces APP14', array(0, '', 0, 0x4000, 0, 3), array($status, $err, $c->ColorTransform, $c->Flag0, $c->Flag1, array_search('APP14', markers($out))));
list($status, $out, $err) = sample('jpegcolor.php', array('-f', "$dataDir/cmyk.jpg", '-c', '1,2'));
check('jpegcolor -c count mismatch', array(1, '', "nComponents:2 !== SOF_Nf:4\n"), array($status, $out, $err));

// usage
foreach (array('jpegdump.php', 'jpegrebuild.php', 'jpegrepair.php', 'jpegdelmeta.php', 'jpegchunk.php', 'jpegcolor.php', 'jpegcat.php') as $script) {
    list($status, $out, $err) = sample($script, array());
    check("$script usage", array(1, 1), array($status, preg_match('/Usage/', $out.$err)));
}
rmdir($tmpDir);

echo "passed: $passed, failed: $failed".PHP_EOL;
exit($failed ? 1 : 0);
