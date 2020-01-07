<?php

if (is_readable('vendor/autoload.php')) {
    require 'vendor/autoload.php';
} else {
    require_once 'IO/JPEG.php';
}

$options = getopt("f:S");

function usage() {
    echo "Usage: php jpegrepair.php [-S] -f <jpegfile>".PHP_EOL;
}

if ((isset($options['f']) === false) ||
    (is_readable($options['f']) === false)) {
    usage();
    exit(1);
}

$jpegfile = $options['f'];
$jpegdata = file_get_contents($jpegfile);

$sosScan = !isset($options['S']);

$jpeg = new IO_JPEG();
$jpeg->parse($jpegdata, true, $sosScan);

$catdata = "";
foreach ($jpeg->_jpegChunk as $idx => $chunk) {
    $marker = $chunk->marker;
    $marker_name = $chunk->get_marker_name();
    $data = $chunk->data;
    if (($marker === 0xD8) || ($marker === 0xD9) || $marker === 0xDA) { // SOS) { // SOI or EOI or SOS
        if ($marker === 0xDA) { // SOS
            $bit = new IO_Bit();
            $bit->input($data);
            $data = "";
            $prev = null;
            while($bit->hasNextData(1)) {
                $c = $bit->getData(1);
                if ($prev === "\xff") {
                    if ($c !== "\x00") {
                        $data .= "\x00";
                    }
                }
                $data .= $c;
                $prev = $c;
            }
        }
        $data = pack("CC", 0xff, $marker) . $data;
    } else {
        $length = 2 + strlen($chunk->data);
        $data = pack("CC", 0xff, $marker) . pack("n", $length) . $data;
    }
    $catdata .= $data;
    $prev_marker = $marker;
}

echo $catdata;

exit(0);
