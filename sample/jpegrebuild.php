<?php

require 'IO/JPEG/Editor.php';

if ($argc != 2) {
    echo "Usage: php jpegrebuild.php <jpeg_file>\n";
    echo "ex) php jpegrebuild.php test.jpg\n";
    exit(1);
}

assert(is_readable($argv[1]));

$jpegdata = file_get_contents($argv[1]);

$jpeg = new IO_JPEG_Editor();

$jpeg->parse($jpegdata);

$jpeg->rebuild();

echo $jpeg->build();

exit(0);
