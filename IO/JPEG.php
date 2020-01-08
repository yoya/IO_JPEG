<?php

/*
  IO_JPEG class -- v3.2
  (c) 2012/11/06 yoya@awm.jp
  ref) http://pwiki.awm.jp/~yoya/?JPEG
 */

if (is_readable('vendor/autoload.php')) {
    require 'vendor/autoload.php';
} else {
    require_once 'IO/Bit.php';
    require_once 'IO/JPEG/Chunk.php';
}

class IO_JPEG {
    var $_jpegdata = null;
    var $_jpegChunk = array();
    function parse($jpegdata, $eoiFinish = true, $sosScan = true) {
        $this->_jpegdata = $jpegdata;
        $bitin = new IO_Bit();
        $bitin->input($jpegdata);
        while ($bitin->hasNextData()) {
            $chunk = new IO_JPEG_Chunk();
            $chunk->parse($bitin, $sosScan);
            if (is_null($chunk->marker)) {
                break;
            }
            $this->_jpegChunk[] = $chunk;
            if ($eoiFinish && ($chunk->marker == 0xD9)) { // EOI (End of Image)
                break;
            }
        }
    }
    function dump($opts) {
        $opts['hexdump'] = !empty($opts['hexdump']);
        $opts['detail'] = isset($opts['detail'])?$opts['detail']:false;
        if (count($this->_jpegChunk) == 0) {
            throw new Exception("no jpeg chunk");
        }
        if ($opts['hexdump']) {
            $bitin = new IO_Bit();
            $bitin->input($this->_jpegdata);
            $opts["bitio"] = $bitin;
        }
        foreach ($this->_jpegChunk as $chunk) {
            $chunk->dump($opts);
        }
    }
    function build($opts = []) {
        if (count($this->_jpegChunk) == 0) {
            throw new Exception("no jpeg chunk");
        }
        $bit = new IO_Bit();
        foreach ($this->_jpegChunk as $chunk) {
            $chunk->build($bit);
        }
        echo $bit->output();
    }
}
