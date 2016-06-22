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
    var $_parseChunkDetailAllDone = false;
    function parse($jpegdata, $eoiFinish = true, $sosScan = true) {
        $this->_jpegdata = $jpegdata;
        $bitin = new IO_Bit();
        $bitin->input($jpegdata);
        while ($bitin->hasNextData()) {
	    list($startOffset, $dummy) = $bitin->getOffset();
            $marker1 = $bitin->getUI8();
            if ($marker1 != 0xFF) {
                fprintf(STDERR, "WARNING: parse marker1=0x%02X offset:0x%X\n", $marker1, $startOffset);
                continue;
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
    function _parseChunkDetailAll() {
        foreach ($this->_jpegChunk as &$chunk) {
	    $this->_parseChunkDetail($chunk);
	}
	$this->_parseChunkDetailAllDone = true;
    }
    function _parseChunkDetail(&$chunk) {
        if (isset($chunk['data'])) {
	  $chunkDataBitin = new IO_Bit();
	  $chunkDataBitin->input($chunk['data']);
	}
	switch ($chunk['marker']) {
	case 0xD8: // SOI
	case 0xD9: // EOI
	  // nothing to do
	  break;
	case 0xE0: // APP0
	  $chunk['identifier'] = $chunkDataBitin->getData(5);
	  if ($chunk['identifier'] === "JFIF\0") {
	      $version1 = $chunkDataBitin->getUI8();
	      $version2 = $chunkDataBitin->getUI8();
	      $chunk['version'] = sprintf("%d.%02d", $version1, $version2);
	  } else {
	      $chunk['extension_code'] = $chunkDataBitin->getUI8();
	      $chunk['extension_data'] = $chunkDataBitin->getDataUntil(false);
	      
	  }
	  break;
	case 0xC0: // SOF0
	case 0xC1: // SOF1
	case 0xC2: // SOF2
	case 0xC3: // SOF3
	case 0xC5: // SOF5
	case 0xC6: // SOF6
	case 0xC7: // SOF7
	  $chunk['P'] = $chunkDataBitin->getUI8();
	  $chunk['Y'] = $chunkDataBitin->getUI16BE();
	  $chunk['X'] = $chunkDataBitin->getUI16BE();
	  $SOF_Nf = $chunkDataBitin->getUI8();
	  $chunk['Nf'] = $SOF_Nf;
	  $SOF_C = Array();
	  $SOF_H = Array();
	  $SOF_V = Array();
	  $SOF_Tq =Array();
	  for ($i = 0 ; $i < $SOF_Nf; $i++) {
	      $SOF_C[$i] = $chunkDataBitin->getUI8();
	      $SOF_H[$i] = $chunkDataBitin->getUIBits(4);
	      $SOF_V[$i] = $chunkDataBitin->getUIBits(4);
	      $SOF_Tq[$i] = $chunkDataBitin->getUI8();
	  }
	  $chunk['C'] = $SOF_C;
	  $chunk['H'] =$SOF_H;
	  $chunk['V'] =$SOF_V;
	  $chunk['Tq'] =$SOF_Tq;
	  break;
	case 0xDB: // DQT
	  $DQT_Pq =  $chunkDataBitin->getUIBits(4);
	  $DQT_Tq =  $chunkDataBitin->getUIBits(4);
	  $chunk['Pq'] = $DQT_Pq;
	  $chunk['Tq'] = $DQT_Tq;
	  if ($DQT_Pq === 0) {
	      for ($k = 0 ; $k < 64 ; $k++) {
		  $DQT_Q[$k] =  $chunkDataBitin->getUI8();
	      }
	  } else {
	      for ($k = 0 ; $k < 64 ; $k++) {
	          $DQT_Q[$k] =  $chunkDataBitin->getUI16BE();
	      }
	  }
	  $chunk['Q'] = $DQT_Q;
	break;
	case 0xC4: // DHT
	  $chunk['Tc'] = $chunkDataBitin->getUIBits(4);
	  $chunk['Th'] = $chunkDataBitin->getUIBits(4);
	  $DHT_L = Array();
	  for ($i = 0 ; $i < 16 ; $i++) {
	      $DHT_L[$i] = $chunkDataBitin->getUI8();
	  }
	  $chunk['L'] = $DHT_L;
	  $DHT_V = Array();
	  for ($i = 0 ; $i < 16 ; $i++) {
	      $li = $DHT_L[$i];
	      if ($li > 0) {
		  $DHT_V[$i] = Array();
		  for ($j = 0 ; $j < $li ; $j++) {
		      $DHT_V[$i][$j] = $chunkDataBitin->getUI8();
		  }
	      }
	  }
	  $chunk['V'] = $DHT_V;
	  break;
	}
    }
    function dump($opts) {
        if (count($this->_jpegChunk) == 0) {
            $this->_splitChunk(false);
        }
	if (isset($opts['hexdump'])) {
	    $bitin = new IO_Bit();
	    $bitin->input($this->_jpegdata);
	}
	if (isset($opts['detail'])) {
	    if ($this->_parseChunkDetailAllDone === false) {
	        $this->_parseChunkDetailAll();
	    }
	}

        foreach ($this->_jpegChunk as $chunk) {
            $marker = $chunk['marker'];
            $marker_name = $this->marker_name_table{$marker};
            if (is_null($chunk['data'])) {
                echo "$marker_name:".PHP_EOL;
            } else {
		if (isset($chunk['length'])) {
		    $length = $chunk['length'];
		    echo "$marker_name: length=$length".PHP_EOL;
		} else {
		    echo "$marker_name: length=(null)".PHP_EOL;
		}
            }
	    if (isset($opts['detail'])) {
	        $this->dumpChunkDetail($chunk);
	    }
	    if (isset($opts['hexdump'])) {
	        if (is_null($chunk['data'])) {
		    $bitin->hexdump($chunk['startOffset'], 2);
		} else {
		    if (isset($chunk['length'])) {
		        $length = $chunk['length'];
		    } else {
		      $length = 2 + strlen($chunk['data']);
		    }
		    $bitin->hexdump($chunk['startOffset'], 2 + $length);
		}
	    }
        }
    }
    function dumpChunkDetail($chunk) {
        switch ($chunk['marker']) {
	case 0xD8: // SOI
	  echo "\tStart Of Image\n";
	  break;
	case 0xD9: // EOI
	  echo "\tEnd Of Image\n";
	  break;
	case 0xE0: // APP0
	  $identifier = $chunk['identifier'];
	  echo "\tidentifier:$identifier\n";
	  if ($identifier === "JFIF\0") {
	      $version = $chunk['version'];
	      echo "\tverison:$version\n";
	  } else {
	      $extension_code = $chunk['extension_code'];
	      $extension_data = $chunk['extension_data'];
	      echo "\textension_code:$extension_code\n";
	      echo "\textension_data:$extension_data\n";
	  }
	  break;
	case 0xC0: // SOF0
	case 0xC1: // SOF1
	case 0xC2: // SOF2
	case 0xC3: // SOF3
	case 0xC5: // SOF5
	case 0xC6: // SOF6
	case 0xC7: // SOF7
	  $SOF_P = $chunk['P'];
	  $SOF_Y = $chunk['Y'];
	  $SOF_X = $chunk['X'];
	  $SOF_Nf = $chunk['Nf'];
	  echo "\tP:$SOF_P Y:$SOF_Y X:$SOF_X\n";
	  echo "\tNf:$SOF_Nf\n";
	  $SOF_C = $chunk['C'];
	  $SOF_H = $chunk['H'];
	  $SOF_V = $chunk['V'];
	  $SOF_Tq = $chunk['Tq'];
	  for ($i = 0 ; $i < $SOF_Nf; $i++) {
	    echo "\t[i=$i]: C:{$SOF_C[$i]} H:{$SOF_H[$i]} V:{$SOF_V[$i]} Tq:{$SOF_Tq[$i]}\n";
	  }
	  break;
	case 0xDB: // DQT
	  $DQT_Pq =  $chunk['Pq'];
	  $DQT_Tq =  $chunk['Tq'];
	  echo "\tPq:$DQT_Pq Tq:$DQT_Tq\n";

	  $DQT_Q = $chunk['Q'];
	  for ($k = 0 ; $k < 64 ; $k+= 8) {
	      $DQT_Q_k8 = array_slice($DQT_Q, $k, 8);
	      array_walk($DQT_Q_k8, create_function('&$v,$k', '$v = sprintf("%02x", $v);'));
	      printf("\tQ[k=0x%02x]:", $k);
	      echo join(' ', $DQT_Q_k8)."\n";
	      
	  }
	  break;
	case 0xC4: // DHT
	  $DHT_Tc = $chunk['Tc'];
	  $DHT_Th = $chunk['Th'];
	  $DHT_L = $chunk['L'];
	  $DHT_V = $chunk['V'];
	  echo "\tTc:$DHT_Tc Th:$DHT_Th\n";
	  echo "\tLi:".join(" ", $DHT_L)."\n";
	  foreach ($DHT_V as $i => $DHT_Vi) {
	      array_walk($DHT_Vi, create_function('&$v,$k', '$v = sprintf("%02x", $v);'));
	      echo "\tVij[i=$i]:".join(" ", $DHT_Vi)."\n";
	  }
	  break;
	  //	    case 0xDA: //SOS
	  //	      $SOS_Ns = $chunkDataBitin->getUI8();
	  //	      echo "\tNs=$SOS_Ns\n";
	  // break;
	}
    }
}
