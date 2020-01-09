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
    function updateOrAppendChunkBetween($chunk, $beforeChunk, $afterChunk) {
        if (is_null($beforeChunk) && is_null($afterChunk)) {
            throw new Exception("is_null(beforeChunk) && is_null(afterChunk)");
        }
        foreach ($this->_jpegChunk as $idx => $c) {
            if ($c->marker === $chunk->marker) {
                $this->_jpegChunk[$idx] = $chunk;  // update
                return true;
            }
        }
        $before = true;
        if (is_null($beforeChunk)) {
            $before = false;
        }
        foreach ($this->_jpegChunk as $idx => $c) {  // insert
            if ($before) {
                if ($c->marker === $beforeChunk) {
                    if (is_null($afterChunk)) {
                        array_splice($this->_jpegChunk, $idx + 1, 0, $chunk);
                        return true;
                    }
                    $before = true;
                }
            } else {
                if ($c->marker === $afterChunk) {
                        array_splice($this->_jpegChunk, $idx, 0, $chunk);
                        return true;
                }
            }
        }
        return false;
    }
    function removeChunk($marker) {
        foreach ($this->_jpegChunk as $idx => $chunk) {
            if ($chunk->marker === $marker) {
                unset($this->_jpegChunk[$idx]);
            }
        }
    }
}
