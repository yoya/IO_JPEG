<?php

/*
  IO_JPEG_Editor class
  (c) 2020/01/09 yoya@awm.jp
 */

if (is_readable('vendor/autoload.php')) {
    require 'vendor/autoload.php';
} else {
    require_once 'IO/Bit.php';
    require_once 'IO/JPEG.php';
}

class IO_JPEG_Editor extends IO_JPEG {
    function rebuild() {
        foreach ($this->_jpegChunk as &$chunk) {
            $chunk->_parseChunkDetail();
            $chunk->data = null;
        }
    }
}
