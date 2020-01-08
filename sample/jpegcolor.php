<?php

if (is_readable('vendor/autoload.php')) {
    require 'vendor/autoload.php';
} else {
    require_once 'IO/JPEG/Editor.php';
}

$options = getopt("f:ja:c:");

function usage() {
    echo "Usage: php jpegcolor.php -f <jpegfile>".PHP_EOL;
    echo "Usage: php jpegcolor.php -f <jpegfile> -j -a <coltrans> -c <id,...>".PHP_EOL;
}

if ((isset($options['f']) === false) ||
    (is_readable($options['f']) === false)) {
    usage();
    exit(1);
}
$jfifMarker_add = isset($options['j']);
$adobeMarker_add = isset($options['a']);
$componentIDs_set = isset($options['c'])?explode(",", $options['c']):null;

$jpegfile = $options['f'];
$jpegdata = file_get_contents($jpegfile);

$eoiFinish = false;
$sosScan = false;
$jpeg = new IO_JPEG_Editor();
$jpeg->parse($jpegdata, $eoiFinish, $sosScan);

$opts = ["detail" => true];
$jpeg->rebuild();

$jfifMarkerChunk = null;
$adobeMarkerChunk = null;
$sofChunk = null;

foreach ($jpeg->_jpegChunk as $idx => $chunk) {
    $marker = $chunk->marker;
    $marker_name = $chunk->get_marker_name();
    $data = $chunk->data;
    switch ($marker) {
    case 0xE0:  // APP0 (JFIF marker)
        $jfifMarkerChunk = $chunk;
        break;
    case 0xEE:  // APP14 (Adobe marker)
        $adobeMarkerChunk = $chunk;
        break;
    case 0xC0: // SOF0
    case 0xC1: // SOF1
    case 0xC2: // SOF2
    case 0xC3: // SOF3
    case 0xC5: // SOF5
    case 0xC6: // SOF6
    case 0xC7: // SOF7
        $sofChunk = $chunk;
        break;
    }
}

if ((!$jfifMarker_add ) && (! $adobeMarker_add) && is_null($componentIDs_set)) {
    if (! is_null($jfifMarkerChunk)) {
        $jfifMarkerChunk->dump($opts);
    }
    if (! is_null($adobeMarkerChunk)) {
        $adobeMarkerChunk->dump($opts);
    }
    if (! is_null($sofChunk)) {
        $chunk = $sofChunk;
        $marker_name = $chunk->get_marker_name();
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
    }
}
exit(0);
