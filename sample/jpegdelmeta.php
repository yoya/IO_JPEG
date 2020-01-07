<?php

if (is_readable('vendor/autoload.php')) {
    require 'vendor/autoload.php';
} else {
    require_once 'IO/JPEG.php';
}

$options = getopt("f:");

function usage() {
    echo "Usage: php jpegdelmeta.php -f <jpegfile>".PHP_EOL;
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

$jpeg_mandatory_chunk =
	array(
		  0xD8, // SOI
		  0xE0, // APP0
		  0xDB, // DQT
		  0xC0, 0xC1, 0xC2, 0xC3, 0xC4, 0xC5, 0xC6, 0xC7, // SOF0 - SOF7
		  0xC8, 0xC9, 0xCA, 0xCB, 0xCC, 0xCD, 0xCE, 0xCF, // SOF8 - SOF15
		  0xC4, // DHT
		  0xDD, // DRI
		  0xDA, // SOS
		  0xD0, 0xD1, 0xD2, 0xD3, 0xD4, 0xD5, 0xD6, 0xD7, 0xD8, // RST0 - RST7
		  0xD9, // EOI
		  );

$output_data_list = array();

foreach ($jpeg->_jpegChunk as $idx => $chunk) {
	$marker = $chunk->marker;
	if (in_array($marker, $jpeg_mandatory_chunk) === false) {
		continue; // skip meta data
	}
	$marker_name = $chunk->get_marker_name();
	$data = $chunk->data;
	if (($marker === 0xD8) || ($marker === 0xD9) || $marker === 0xDA) { // SOS) { // SOI or EOI or SOS
		$data = pack("CC", 0xff, $marker) . $data;
	} else {
		$length = 2 + strlen($chunk->data);
		$data = pack("CC", 0xff, $marker) . pack("n", $length) . $data;
	}
	$output_data_list []= $data;
}
echo implode('', $output_data_list);

exit(0);
