<?php

require_once 'IO/JPEG.php';

$options = getopt("f:hdS");

function usage() {
    echo "Usage: php jpegdump.php [-h] [-d] [-S] -f <jpegfile>".PHP_EOL;
}

if ((isset($options['f']) === false) ||
    (is_readable($options['f']) === false)) {
    usage();
    exit(1);
}
$opts = array();

if (isset($options['h'])) {
  $opts['hexdump'] = true;
}
if (isset($options['d'])) {
  $opts['detail'] = true;
}
$noSosScan = isset($options['S']);

$jpegfile = $options['f'];
$jpegdata = file_get_contents($jpegfile);

$jpeg = new IO_JPEG();
$jpeg->parse($jpegdata, true, ! $noSosScan);

$jpeg->dump($opts);

exit(0);
