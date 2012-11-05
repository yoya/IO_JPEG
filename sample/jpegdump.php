<?php

require_once 'IO/JPEG.php';

$options = getopt("f:");

function usage() {
    echo "Usage: php jpegdump.php -f <jpegfile>".PHP_EOL;
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

$jpeg->dumpChunk();

exit(0);
