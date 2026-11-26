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
        // markers that _buildChunkDetail() can rebuild (others keep data)
        $rebuildable = [0xE0, 0xC0, 0xC1, 0xC2, 0xC3, 0xC5, 0xC6, 0xC7,
                        0xDB, 0xC4, 0xDA, 0xDD,
                        0xD0, 0xD1, 0xD2, 0xD3, 0xD4, 0xD5, 0xD6, 0xD7,
                        0xEE];
        foreach ($this->_jpegChunk as &$chunk) {
            if (in_array($chunk->marker, $rebuildable, true)) {
                $chunk->_parseChunkDetail();
                $chunk->data = null;
            }
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
                        array_splice($this->_jpegChunk, $idx + 1, 0, [$chunk]);
                        return true;
                    }
                    $before = true;
                }
            } else {
                if ($c->marker === $afterChunk) {
                        array_splice($this->_jpegChunk, $idx, 0, [$chunk]);
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
