<?php

if (is_readable('vendor/autoload.php')) {
    require 'vendor/autoload.php';
} else {
    require_once 'IO/JPEG.php';
}

$options = getopt("f:scS");

function usage() {
    echo "Usage: php jpegchunk.php -f <jpegfile> -s # split".PHP_EOL;
    echo "Usage: php jpegchunk.php -f <jpegfile> -s -c # split cat mode".PHP_EOL;
}

if ((isset($options['f']) === false) ||
    (is_readable($options['f']) === false)) {
    usage();
    exit(1);
}

$jpegfile = $options['f'];
$jpegdata = file_get_contents($jpegfile);

$sosScan = ! isset($options['S']);

$jpeg = new IO_JPEG();
$jpeg->parse($jpegdata, true, $sosScan);

$catdata = '';

$prev_marker = 0;


if (isset($options['s'])) {
	foreach ($jpeg->_jpegChunk as $idx => $chunk) {
		$marker = $chunk->marker;
		$marker_name = $jpeg->marker_name_table[$marker];
        if (isset($options['c'])) {
            $filename = sprintf("%02d_%s.jpg", $idx, $marker_name);
        } else {
            $filename = sprintf("%02d_%s.jc", $idx, $marker_name);
        }
		$data = $chunk->data;
		if (($marker === 0xD8) || ($marker === 0xD9) || $marker === 0xDA) { // SOS) { // SOI or EOI or SOS
			$data = pack("CC", 0xff, $marker) . $data;
		} else {
			$length = 2 + strlen($chunk->data);
			$data = pack("CC", 0xff, $marker) . pack("n", $length) . $data;
		}
        if (isset($options['c'])) {
            $catdata .= $data;
            file_put_contents($filename, $catdata);
        } else {
            file_put_contents($filename, $data);
        }
        $prev_marker = $marker;
	}
}

exit(0);
