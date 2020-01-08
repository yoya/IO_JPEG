<?php

if (is_readable('vendor/autoload.php')) {
    require 'vendor/autoload.php';
} else {
    require_once 'IO/JPEG/Editor.php';
}

$options = getopt("f:");

function usage() {
    echo "Usage: php jpegcolor.php -f <jpegfile>".PHP_EOL;
}

if ((isset($options['f']) === false) ||
    (is_readable($options['f']) === false)) {
    usage();
    exit(1);
}

$jpegfile = $options['f'];
$jpegdata = file_get_contents($jpegfile);

$eoiFinish = false;
$sosScan = false;
$jpeg = new IO_JPEG_Editor();
$jpeg->parse($jpegdata, $eoiFinish, $sosScan);

$opts = ["detail" => true];
$jpeg->rebuild();

foreach ($jpeg->_jpegChunk as $idx => $chunk) {
    $marker = $chunk->marker;
    $marker_name = $chunk->get_marker_name();
    $data = $chunk->data;
    switch ($marker) {
    case 0xE0:  // APP0 (JFIF marker)
        $chunk->dump($opts);
        break;
    case 0xEE:  // APP14 (Adobe marker)
        $chunk->dump($opts);
        break;
    case 0xC0: // SOF0
    case 0xC1: // SOF1
    case 0xC2: // SOF2
    case 0xC3: // SOF3
    case 0xC5: // SOF5
    case 0xC6: // SOF6
    case 0xC7: // SOF7
        echo "$marker_name:\n";
        $SOF_Nf = $chunk->Nf;
        $SOF_C  = $chunk->C;
        echo "\tComponentID: ";
        for ($i = 0; $i < $SOF_Nf; $i++) {
            $compId = $SOF_C[$i];
            echo "$compId";
            if (4 < $compId) {
                echo "(".chr($compId).")";
            }
            echo " ";
        }
        echo "\n";
        break;
    }
}

exit(0);
