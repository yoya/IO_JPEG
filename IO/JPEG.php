<?php

require_once 'IO/Bit.php';

class IO_JPEG {
    var $marker_name_table = array(
        0xD8 => 'SOI',
        0xE0 => 'APP0',  0xE1 => 'APP1',  0xE2 => 'APP2',  0xE3 => 'APP3',
        0xE4 => 'APP4',  0xE5 => 'APP5',  0xE6 => 'APP6',  0xE7 => 'APP7',
        0xE8 => 'APP8',  0xE9 => 'APP9',  0xEA => 'APP10', 0xEB => 'APP11',
        0xEC => 'APP12', 0xED => 'APP13', 0xEE => 'APP14', 0xEF => 'APP15',
        0xFE => 'COM',
        0xDB => 'DQT',
        0xC0 => 'SOF0', 0xC1 => 'SOF1',  0xC2 => 'SOF2',  0xC3 => 'SOF3',
        0xC5 => 'SOF5', 0xC6 => 'SOF6',  0xC7 => 'SOF7',
        0xC8 => 'JPG',  0xC9 => 'SOF9',  0xCA => 'SOF10', 0xCB => 'SOF11',
        0xCC => 'DAC',  0xCD => 'SOF13', 0xCE => 'SOF14', 0xCF => 'SOF15',
        0xC4 => 'DHT',
        0xDA => 'SOS',
        0xD0 => 'RST0', 0xD1 => 'RST1', 0xD2 => 'RST2', 0xD3 => 'RST3',
        0xD4 => 'RST4', 0xD5 => 'RST5', 0xD6 => 'RST6', 0xD7 => 'RST7',
        0xDD => 'DRI',
        0xD9 => 'EOI',
        0xDC => 'DNL',   0xDE => 'DHP',  0xDF => 'EXP',
        0xF0 => 'JPG0',  0xF1 => 'JPG1', 0xF2 => 'JPG2',  0xF3 => 'JPG3',
        0xF4 => 'JPG4',  0xF5 => 'JPG5', 0xF6 => 'JPG6',  0xF7 => 'JPG7',
        0xF8 => 'JPG8',  0xF9 => 'JPG9', 0xFA => 'JPG10', 0xFB => 'JPG11',
        0xFC => 'JPG12', 0xFD => 'JPG13'
        );
    var $stdChunkOrder = array(
        0xD8, // SOI
        0xE0, // APP0
        0xDB, // DQT
        0xC0, // SOF0
        0xC4, // DHT
        0xDA, // SOS
        0xD9, // EOI
        );
    var $_jpegdata = null;
    var $_jpegChunk = array();
    function input($jpegdata) {
        $this->_jpegdata = $jpegdata;
    }
    function _splitChunk($eoiFinish = true, $sosScan = true) {
        $bitin = new IO_Bit();
        $bitin->input($this->_jpegdata);
        while ($bitin->hasNextData()) {
	    list($startOffset, $dummy) = $bitin->getOffset();
            $marker1 = $bitin->getUI8();
            if ($marker1 != 0xFF) {
                fprintf(STDERR, "dumpChunk: marker1=0x%02X", $marker1);
                return false;
            }
            $marker2 = $bitin->getUI8();
            switch ($marker2) {
            case 0xD8: // SOI (Start of Image)
	        $this->_jpegChunk[] = array('marker' => $marker2, 'data' => null, 'length' => null, 'startOffset' => $startOffset);
                continue;
            case 0xD9: // EOI (End of Image)
                $this->_jpegChunk[] = array('marker' => $marker2, 'data' => null, 'length' => null, 'startOffset' => $startOffset);
                if ($eoiFinish) {
                    break 2; // while break;
                }
                continue;
            case 0xDA: // SOS
                if ($sosScan === false) {
                    $remainData = $bitin->getDataUntil(false);
                    $this->_jpegChunk[] = array('marker' => $marker2, 'data' => $remainData, 'length' => null, 'startOffset' => $startOffset);
                    break 2 ; // while break;
                }
            case 0xD0: case 0xD1: case 0xD2: case 0xD3: // RST
            case 0xD4: case 0xD5: case 0xD6: case 0xD7: // RST
                list($chunk_data_offset, $dummy) = $bitin->getOffset();
                while (true) {
                    $next_marker1 = $bitin->getUI8();
                    if ($next_marker1 != 0xFF) {
                        continue;
                    }
                    $next_marker2 = $bitin->getUI8();
                    if ($next_marker2 == 0x00) {
                        continue;
                    }
                    
                    $bitin->incrementOffset(-2, 0); // back from next marker
                    list($next_chunk_offset, $dummy) = $bitin->getOffset();
                    $length = $next_chunk_offset - $chunk_data_offset;
                    $bitin->setOffset($chunk_data_offset, 0);
                    $this->_jpegChunk[] = array('marker' => $marker2, 'data' => $bitin->getData($length), 'length' => null, 'startOffset' => $startOffset);
                    break;
                }
                break;
            default:
                $length = $bitin->getUI16BE();
                $this->_jpegChunk[] = array('marker' => $marker2, 'data' => $bitin->getData($length - 2), 'length' => $length, 'startOffset' => $startOffset);
                continue;
            }
        }
    }

    function dumpChunk($opts) { // for debug
        if (count($this->_jpegChunk) == 0) {
            $this->_splitChunk(false);
        }
	if (isset($opts['hexdump'])) {
	    $bitin = new IO_Bit();
	    $bitin->input($this->_jpegdata);
	}
        foreach ($this->_jpegChunk as $chunk) {
            $marker = $chunk['marker'];
            $marker_name = $this->marker_name_table{$marker};
            if (is_null($chunk['data'])) {
                echo "$marker_name:".PHP_EOL;
            } else {
                $length = strlen($chunk['data']);
                echo "$marker_name: length=$length".PHP_EOL;
		if (isset($opts['hexdump'])) {
		    $bitin->hexdump($chunk->startOffset, 2 + $length);
		}
            }
        }
    }
}
