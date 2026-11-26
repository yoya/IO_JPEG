<?php

if (is_readable('vendor/autoload.php')) {
    require 'vendor/autoload.php';
} else {
    require_once 'IO/JPEG.php';
}

function usage() {
    echo "Usage: php jpegcat.php <jpegchunk> [<jpegchunk2> [...] ]".PHP_EOL;
}

if ($argc < 2) {
    usage();
    exit (1);
}

foreach (array_slice($argv, 1) as $chunkfile) {
    $chunk = file_get_contents($chunkfile);
    $markerPos = 1;
    while (($markerPos < strlen($chunk) - 1) && ($chunk[$markerPos] === "\xff")) {
        $markerPos++; // skip fill bytes
    }
    $marker = ord($chunk[$markerPos]);
    if (($marker ===  0xD8) || // SOI (Start of Image)
        ($marker ===  0xD9)) { // EOI (End of Image)
        echo $chunk;
    } else if (($marker == 0xDA) || // SOS
        ((0xD0 <= $marker) && ($marker <=  0xD7))) { // RSTn
        echo $chunk;
    } else {
        $lengthArr = unpack("n", substr($chunk, $markerPos + 1, 2));
        $length = $lengthArr[1];
        if ($length === (2 + strlen($chunk))) {
            echo $chunk;
        } else {
            $markerData = substr($chunk, 0, $markerPos + 1);
            $data = substr($chunk, $markerPos + 3);
            $length = 2 + strlen($data);
            $lengthData = pack("n", $length);
            echo $markerData . $lengthData . $data;
        }
    }
}

exit(0);
