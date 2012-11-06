<?php

require_once 'IO/JPEG.php';

$options = getopt("f:h");

function usage() {
    echo "Usage: php jpegdump.php [-h] -f <jpegfile>".PHP_EOL;
}

if ((isset($options['f']) === false) ||
    (is_readable($options['f']) === false)) {
    usage();
    exit(1);
}

$jpegfile = $options['f'];
$jpegdata = file_get_contents($jpegfile);

$jpeg = new IO_JPEG();
$jpeg->input($jpegdata);

$opts = array();

if (isset($options['h'])) {
  $opts['hexdump'] = true;
}


$jpeg->dumpChunk($opts);

exit(0);
