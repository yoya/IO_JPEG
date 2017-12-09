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
    $marker = ord($chunk{1});
    if (($marker ===  0xD8) || // SOI (Start of Image)
        ($marker ===  0xD9)) { // EOI (End of Image)
        echo $chunk;
    } else if (($marker == 0xDA) || // SOS
        ((0xD0 <= $marker) && ($marker <=  0xD7))) { // RSTn
        echo $chunk;
    } else {
        $lengthArr = unpack("n", substr($chunk, 2, 2));
        $length = $lengthArr[1];
        if ($length === (2 + strlen($chunk))) {
            echo $chunk;
        } else {
            $markerData = substr($chunk, 0, 2);
            $data = substr($chunk, 4);
            $length = 2 + strlen($data);
            $lengthData = pack("n", $length);
            echo $markerData . $lengthData . $data;
        }
    }
}

exit(0);
