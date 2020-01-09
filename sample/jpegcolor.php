<?php

if (is_readable('vendor/autoload.php')) {
    require 'vendor/autoload.php';
} else {
    require_once 'IO/JPEG/Editor.php';
}

$options = getopt("f:ja:c:JA");

function usage() {
    echo "Usage: php jpegcolor.php -f <jpegfile> [-j] [-a <coltrans>] [-c <id...>] [-J] [-A]".PHP_EOL;
    echo "ex) php jpegcolor.php -f input.jpg -J -a 2 -c CMYK".PHP_EOL;
    echo "ex) php jpegcolor.php -f input.jpg -j -A -c '3,1,2' ".PHP_EOL;
}

if ((isset($options['f']) === false) ||
    (is_readable($options['f']) === false)) {
    usage();
    exit (1);
}
$jfifMarker_add = isset($options['j']);
$jfifMarker_del = isset($options['J']);
$adobeMarker_colorTransform = isset($options['a'])?intval($options['a']):null;
$adobeMarker_del = isset($options['A']);
$componentIDs = isset($options['c'])?$options['c']:null;

$jpegfile = $options['f'];
$jpegdata = file_get_contents($jpegfile);

$eoiFinish = false;
$sosScan = false;
$jpeg = new IO_JPEG_Editor();

try {
    $jpeg->parse($jpegdata, $eoiFinish, $sosScan);
} catch (Exception $e) {
    fprintf(STDERR, "JPEG parser got failed:%s\n", $e->getMessage());;
    exit (1);
}

$opts = ["detail" => true];
$jpeg->rebuild();

$jfifMarkerChunk = null;
$adobeMarkerChunk = null;
$sofChunk = null;
$sosChunk = null;

foreach ($jpeg->_jpegChunk as $idx => &$chunk) {
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
    case 0xDA: // SOS
        $sosChunk = $chunk;
        break;
    }
}
unset($chunk);

if (is_null($sofChunk) || is_null($sosChunk)) {
    fprintf(STDERR, "error: JPEG need SOFx and SOS chunks\n");;
    exit (1);
}

if ((! $jfifMarker_add) && (! $jfifMarker_del) && is_null($adobeMarker_colorTransform) && (! $adobeMarker_del) && is_null($componentIDs)) {
    // color information dump only
    if (! is_null($jfifMarkerChunk)) {
        $jfifMarkerChunk->dump($opts);
    }
    if (! is_null($adobeMarkerChunk)) {
        $adobeMarkerChunk->dump($opts);
    }
    if (! is_null($sofChunk)) {
        $marker_name = $sofChunk->get_marker_name();
        echo "$marker_name:\n";
        $SOF_Nf = $sofChunk->Nf;
        $SOF_C  = $sofChunk->C;
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
} else {
    // modify jpeg color information
    $soiMarker = 0xD8;  // SOI
    $dqtMarker = 0xDB;  // DQT
    if ($jfifMarker_add && is_null($jfifMarkerChunk)) {
        $jfifMarkerChunk = new IO_JPEG_Chunk();
        $jfifMarkerChunk->marker = 0xE0;  // APP0 (jfif marker)
        $jfifMarkerChunk->identifier = "JFIF\0";
        $jfifMarkerChunk->version = "1.01";
        $jfifMarkerChunk->U = 0;
        $jfifMarkerChunk->Xd = 1;
        $jfifMarkerChunk->Yd = 1;
        $jfifMarkerChunk->Xt = 0;
        $jfifMarkerChunk->Yt = 0;
        $jfifMarkerChunk->RGB = "";
        $jpeg->updateOrAppendChunkBetween($jfifMarkerChunk, $soiMarker, null);
    }
    if ($jfifMarker_del && (!is_null($jfifMarkerChunk))) {
        $jpeg->removeChunk($jfifMarkerChunk->marker);
    }
    if (! is_null($adobeMarker_colorTransform)) {
        $adobeMarkerChunk = new IO_JPEG_Chunk();
        $adobeMarkerChunk->marker = 0xEE;  // APP14 (adobe marker)
        $adobeMarkerChunk->ID = 0;
        $adobeMarkerChunk->Version = 100;
        $adobeMarkerChunk->Flag0 = 0x4000;
        $adobeMarkerChunk->Flag1 = 0x0000;
        $adobeMarkerChunk->ColorTransform = $adobeMarker_colorTransform;
        $jpeg->updateOrAppendChunkBetween($adobeMarkerChunk, null, $dqtMarker);
    }
    if ($adobeMarker_del && (! is_null($adobeMarkerChunk))) {
        $jpeg->removeChunk($adobeMarkerChunk->marker);
    }
    if (! is_null($componentIDs)) {
        // modify SOFx component id
        $sofChunk->_parseChunkDetail();
        $sofChunk->data = null;
        $SOF_Nf = $sofChunk->Nf;
        $SOF_C  = $sofChunk->C;
        if (strlen($componentIDs) === $SOF_Nf) {
            $componentASCIIs = str_split($componentIDs);
            $componentIDs = [];
            for ($i = 0 ; $i < $SOF_Nf; $i++) {
                $componentIDs []= ord($componentASCIIs[$i]);
            }
        } else {
            $componentIDs = explode(",", $componentIDs);
            if (count($componentIDs) !== $SOF_Nf) {
                $nComponents = count($componentIDs);
                fprintf(STDERR, "nComponents:$nComponents !== SOF_Nf:$SOF_Nf\n");
                exit (1);
            }
        }
        $sofChunk->C = $componentIDs;
        // modify SOS component id
        $sosChunk->_parseChunkDetail();
        $sosChunk->data = null;
        $sosChunk->Cs = $componentIDs;
    }
    echo $jpeg->build();
}

exit(0);
