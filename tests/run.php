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

echo "passed: $passed, failed: $failed".PHP_EOL;
exit($failed ? 1 : 0);
