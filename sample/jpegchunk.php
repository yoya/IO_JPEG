<?php

require_once 'IO/JPEG.php';

$options = getopt("f:s");

function usage() {
    echo "Usage: php jpegchunk.php -f <jpegfile> -s # split".PHP_EOL;
}

if ((isset($options['f']) === false) ||
    (is_readable($options['f']) === false)) {
    usage();
    exit(1);
}

$jpegfile = $options['f'];
$jpegdata = file_get_contents($jpegfile);

$jpeg = new IO_JPEG();
$jpeg->parse($jpegdata);

if (isset($options['s'])) {
	foreach ($jpeg->_jpegChunk as $idx => $chunk) {
		$marker = $chunk['marker'];
		$marker_name = $jpeg->marker_name_table[$marker];
		$filename = sprintf("%02d_%s.jc", $idx, $marker_name);
		$data = $chunk['data'];
		if (($marker === 0xD8) || ($marker === 0xD9) || $marker === 0xDA) { // SOS) { // SOI or EOI or SOS
			$data = pack("CC", 0xff, $marker) . $data;
		} else {
			$length = 2 + strlen($chunk['data']);
			$data = pack("CC", 0xff, $marker) . pack("n", $length) . $data;
		}

		file_put_contents($filename, $data);
	}
}

exit(0);
