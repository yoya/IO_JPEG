<?php

/*
  IO_JPEG_Chunk class
  (c) 2020/01/07 yoya@awm.jp
 */

if (is_readable('vendor/autoload.php')) {
    require 'vendor/autoload.php';
} else {
    require_once 'IO/Bit.php';
}

class IO_JPEG_Chunk {
    var $marker;
    var $data;
    var $length;
    var $_parseChunkDetailDone = false;
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
    var $SOF_description_table = array(
        /* SOF0  */ 0xC0 => 'Baseline DCT',
        /* SOF1  */ 0xC1 => 'Extended sequential DCT, Huffman coding',
        /* SOF2  */ 0xC2 => 'Progressive DCT, Huffman coding',
        /* SOF3  */ 0xC3 => 'Lossless (sequential), Huffman coding',
        /* SOF9  */ 0xC9 => 'Extended sequential DCT, arithmatic coding',
        /* SOF10 */ 0xCA => 'Progressive DCT, arithmatic coding',
        /* SOF11 */ 0xCB => 'Lossless (sequentiual), arithmatic coding',
    );
    function get_marker_name($marker = null) {
        if (is_null($marker)) {
            if (is_null($this->marker)) {
                throw new Exception("chunk has no marker");
            }
            $marker = $this->marker;
        }
        return $this->marker_name_table{$marker};
    }
    function parse($bitin, $sosScan = true) {
        while ($bitin->hasNextData()) {
            list($offset, $dummy) = $bitin->getOffset();
            $marker1 = $bitin->getUI8();
            if ($marker1 === 0xFF) {
                break;
            }
            fprintf(STDERR, "WARNING: parse marker1=0x%02X offset:0x%X\n", $marker1, $offset);
        }
        if (! $bitin->hasNextData()) {
            return ;
        }
        $this->startOffset = $offset;
        $marker2 = $bitin->getUI8();
        $this->marker = $marker2;
        $this->data = null;
        $this->length = null;
        switch ($marker2) {
        case 0xD8: // SOI (Start of Image)
            break;
        case 0xD9: // EOI (End of Image)
            break;
        case 0xDA: // SOS
            if ($sosScan === false) {
                $remainData = $bitin->getDataUntil(false);
                if (substr($remainData, -2, 2) === "\xff\xd9") {
                    $bitin->incrementOffset(-2, 0); // back from EOI
                    $remainData = substr($remainData, 0, -2);
                }
                $this->data = $remainData;
                break;
            }
        case 0xD0: case 0xD1: case 0xD2: case 0xD3: // RST
        case 0xD4: case 0xD5: case 0xD6: case 0xD7: // RST
        case 0xDD: // DRI
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
                $this->data = $bitin->getData($length);
                break;
            }
            break;
        default:
            $length = $bitin->getUI16BE();
            $this->data = $bitin->getData($length - 2);
            $this->length = $length;
            break;
        }
    }
    function _parseChunkDetail() {
        if ($this->_parseChunkDetailDone) {
            return ;
        }
        if (! is_null($this->data)) {
            $chunkDataBitin = new IO_Bit();
            $chunkDataBitin->input($this->data);
        }
        switch ($this->marker) {
        case 0xD8: // SOI
        case 0xD9: // EOI
            // nothing to do
            break;
        case 0xE0: // APP0
            $this->identifier = $chunkDataBitin->getData(5);
            if ($this->identifier === "JFIF\0") {
                $version1 = $chunkDataBitin->getUI8();
                $version2 = $chunkDataBitin->getUI8();
                $this->version = sprintf("%d.%02d", $version1, $version2);
                $this->U = $chunkDataBitin->getUI8();
                $this->Xd = $chunkDataBitin->getUI16BE();
                $this->Yd = $chunkDataBitin->getUI16BE();
                $this->Xt = $chunkDataBitin->getUI8();
                $this->Yt = $chunkDataBitin->getUI8();
                $this->RGB = $chunkDataBitin->getDataUntil(false);
            } else {
                $this->extension_code = $chunkDataBitin->getUI8();
                $this->extension_data = $chunkDataBitin->getDataUntil(false);
            }
            break;
        case 0xC0: // SOF0
        case 0xC1: // SOF1
        case 0xC2: // SOF2
        case 0xC3: // SOF3
        case 0xC5: // SOF5
        case 0xC6: // SOF6
        case 0xC7: // SOF7
            $this->P = $chunkDataBitin->getUI8();
            $this->Y = $chunkDataBitin->getUI16BE();
            $this->X = $chunkDataBitin->getUI16BE();
            $SOF_Nf = $chunkDataBitin->getUI8();
            $this->Nf = $SOF_Nf;
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
            $this->C = $SOF_C;
            $this->H =$SOF_H;
            $this->V =$SOF_V;
            $this->Tq =$SOF_Tq;
            break;
        case 0xDB: // DQT
            //
            $DQT_Pq = [];
            $DQT_Tq = [];
            $DQT_Q  = [];
            for ($i = 0 ; $chunkDataBitin->hasNextData(64); $i++) { // XXX
                $Pq = $chunkDataBitin->getUIBits(4);
                $Tq = $chunkDataBitin->getUIBits(4);
                $Q = [];
                if ($Pq === 0) {
                    for ($k = 0 ; $k < 64 ; $k++) {
                        $Q[$k] = $chunkDataBitin->getUI8();
                    }
                } else {
                    for ($k = 0 ; $k < 64 ; $k++) {
                        $Q[$k] = $chunkDataBitin->getUI16BE();
                    }
                }
                $DQT_Pq []= $Pq;
                $DQT_Tq []= $Tq;
                $DQT_Q  []= $Q;
            }
            $this->Pq = $DQT_Pq;
            $this->Tq = $DQT_Tq;
            $this->Q  = $DQT_Q;
            break;
        case 0xC4: // DHT
            $DHT_Tc = [];
            $DHT_Th = [];
            $DHT_L  = [];
            $DHT_V  = [];
            for ($i = 0 ; $chunkDataBitin->hasNextData(1+16+16); $i++) { // XXX
                $Tc = $chunkDataBitin->getUIBits(4);
                $Th = $chunkDataBitin->getUIBits(4);
                $L = [];
                for ($i = 0 ; $i < 16 ; $i++) {
                    $L[$i] = $chunkDataBitin->getUI8();
                }
                $V = [];
                for ($i = 0 ; $i < 16 ; $i++) {
                    $li = $L[$i];
                    if ($li > 0) {
                        $V[$i] = [];
                        for ($j = 0 ; $j < $li ; $j++) {
                            $V[$i][$j] = $chunkDataBitin->getUI8();
                        }
                    }
                }
                $DHT_Tc [] = $Tc;
                $DHT_Th [] = $Th;
                $DHT_L  [] = $L;
                $DHT_V  [] = $V;
            }
            $this->Tc = $DHT_Tc;
            $this->Th = $DHT_Th;
            $this->L  = $DHT_L;
            $this->V  = $DHT_V;
            break;
        case 0xDA: // SOS
            $this->Ls = $chunkDataBitin->getUI16BE();
            $SOS_Ns = $chunkDataBitin->getUI8();
            $this->Ns = $SOS_Ns;
            $SOS_Cs = array();
            $SOS_Td = array();
            $SOS_Ta = array();
            for ($i = 0 ; $i < $SOS_Ns ; $i++) {
                $SOS_Cs []= $chunkDataBitin->getUI8();
                $SOS_Td []= $chunkDataBitin->getUIBits(4);
                $SOS_Ta []= $chunkDataBitin->getUIBits(4);
            }
            $this->Cs = $SOS_Cs;
            $this->Td = $SOS_Td;
            $this->Ta = $SOS_Ta;
            $this->Ss = $chunkDataBitin->getUI8();
            $this->Se = $chunkDataBitin->getUI8();
            $this->Ah = $chunkDataBitin->getUIBits(4);
            $this->Al = $chunkDataBitin->getUIBits(4);
            $this->huffmanData = $chunkDataBitin->getDataUntil(false);
            break;
        case 0xDD: // DRI
            $this->Lr = $chunkDataBitin->getUI16BE();
            $this->Ri = $chunkDataBitin->getUI16BE();
            break;
        case 0xD0: // RST0
        case 0xD1: // RST1
        case 0xD2: // RST2
        case 0xD3: // RST3
        case 0xD4: // RST4
        case 0xD5: // RST5
        case 0xD6: // RST6
        case 0xD7: // RST7
            $this->huffmanData = $chunkDataBitin->getDataUntil(false);
            break;
        case 0xEE: // APP14 (APPE) Adobe Color transform
            // https://www.itu.int/rec/T-REC-T.872-201206-I
            $this->ID = $chunkDataBitin->getData(5);
            $this->Version = $chunkDataBitin->getUI16BE();
            $this->Flag0 = $chunkDataBitin->getUI16BE();
            $this->Flag1 = $chunkDataBitin->getUI16BE();
            $this->ColorTransform = $chunkDataBitin->getUI8();
        }
        $this->_parseChunkDetailDone = true;
    }
    function dump($opts = []) {
        $opts['hexdump'] = !empty($opts['hexdump']);
        if ($opts['hexdump']) {
            $bitin = $opts["bitio"];
        }
        $marker = $this->marker;
        $marker_name = $this->get_marker_name($marker);
        if (is_null($this->data)) {
            echo "$marker_name:".PHP_EOL;
        } else {
            if (is_null($this->length)) {
                echo "$marker_name: length=(null)".PHP_EOL;
            } else {
                $length = $this->length;
                echo "$marker_name: length=$length".PHP_EOL;
            }
        }
        if ($opts['detail']) {
            $this->_parseChunkDetail();
            $this->dumpChunkDetail();
        }
        if ($opts['hexdump']) {
            if (is_null($this->data)) {
                $bitin->hexdump($this->startOffset, 2);
            } else {
                if (is_null($this->length)) {
                    $length = 2 + strlen($this->data);
                } else {
                    $length = $this->length;
                }
                $bitin->hexdump($this->startOffset, 2 + $length);
            }
        }
    }
    function dumpChunkDetail() {
        $marker = $this->marker;
        switch ($marker) {
        case 0xD8: // SOI
            echo "\tStart Of Image\n";
            break;
        case 0xD9: // EOI
            echo "\tEnd Of Image\n";
            break;
        case 0xE0: // APP0
            $identifier = $this->identifier;
            echo "\tidentifier:$identifier\n";
            if ($identifier === "JFIF\0") {
                $version = $this->version;
                echo "\tverison:$version\n";
                echo "\tU:{$this->U} Xd:{$this->Xd} Yd:{$this->Yd} Xt:{$this->Xt} Yt:{$this->Yt} RGB(size:".strlen($this->RGB).")\n";
            } else {
                $extension_code = $this->extension_code;
                $extension_data = $this->extension_data;
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
            $SOF_description = isset($this->SOF_description_table[$marker])?$this->SOF_description_table[$marker]:"Unknown";
            echo "\t($SOF_description)\n";
            $SOF_P = $this->P;
            $SOF_Y = $this->Y;
            $SOF_X = $this->X;
            $SOF_Nf = $this->Nf;
            echo "\tP:$SOF_P Y:$SOF_Y X:$SOF_X Nf:$SOF_Nf\n";
            $SOF_C = $this->C;
            $SOF_H = $this->H;
            $SOF_V = $this->V;
            $SOF_Tq = $this->Tq;
            for ($i = 0 ; $i < $SOF_Nf; $i++) {
                echo "\t[i=".($i+1)."]: C:{$SOF_C[$i]} H:{$SOF_H[$i]} V:{$SOF_V[$i]} Tq:{$SOF_Tq[$i]}\n";
            }
            break;
        case 0xDB: // DQT
            $DQT_Pq = $this->Pq;
            foreach ($DQT_Pq as $idx => $Pq) {
                $Pq_str = ($Pq===0)?"8-bit":(($Pq===1)?"16-bit":"Unknown");
                $Tq = $this->Tq[$idx];
                echo "\tPq:$Pq($Pq_str) Tq:$Tq\n";
                $Q = $this->Q[$idx];
                for ($k = 0 ; $k < 64 ; $k+= 8) {
                    $Q_k8 = array_slice($Q, $k, 8);
                    foreach ($Q_k8 as $k2 => &$v2) {
                        if ($Pq === 0) {
                            $v2 = sprintf("%02x", $v2);  // 8-bits
                        } else {
                            $v2 = sprintf("%04x", $v2); // 16-bits
                        }
                    }
                    printf("\tQ[k=0x%02x]:", $k);
                    echo join(' ', $Q_k8)."\n";
                }
            }
            break;
        case 0xC4: // DHT
            $DHT_Tc = $this->Tc;
            $DHT_Th = $this->Th;
            $DHT_L = $this->L;
            $DHT_V = $this->V;
            foreach ($DHT_Tc as $idx => $Tc) {
                $Th = $DHT_Th[$idx];
                $L  = $DHT_L[$idx];
                $V  = $DHT_V[$idx];
                $Tc_str = ($Tc===0)?"DC":(($Tc===1)?"AC":"Unknown");
                echo "\tTc:$Tc($Tc_str) Th:$Th\n";
                echo "\tLi:".join(" ", $L)."\n";
                foreach ($V as $i => $Vi) {
                    foreach ($Vi as $k => &$v) {
                        $v = sprintf("%02x", $v);
                    }
                    echo "\tVij[i=$i]:".join(" ", $Vi)."\n";
                }
            }
            break;
        case 0xDA: // SOS
            $SOS_Ls = $this->Ls;
            $SOS_Ns = $this->Ns;
            echo "\tLs:$SOS_Ls Ns:$SOS_Ns\n";
            foreach ($this->Cs as $i => $SOS_Cs) {
                $SOS_Td = $this->Ta[$i];
                $SOS_Ta = $this->Td[$i];
                echo "\t[i=".($i+1)."] Cs:$SOS_Cs Td:$SOS_Td Ta:$SOS_Ta\n";
            }
            $SOS_Ss = $this->Ss;
            $SOS_Se = $this->Se;
            $SOS_Ah = $this->Ah;
            $SOS_Al = $this->Al;
            // TODO: data escape check
            $SOS_huffmanData = $this->huffmanData;
            echo "\tSs:$SOS_Ss Se:$SOS_Se Ah:$SOS_Ah Al:$SOS_Al\n";
            echo "\t(Huffman Encoded Data len:".strlen($SOS_huffmanData).")\n";
            break;
        case 0xDD: // DRI
            $DRI_Lr = $this->Lr;
            $DRI_Ri = $this->Ri;
            echo "\tLr:$DRI_Lr Ri:$DRI_Ri\n";
            break;
        case 0xD0: // RST0
        case 0xD1: // RST1
        case 0xD2: // RST2
        case 0xD3: // RST3
        case 0xD4: // RST4
        case 0xD5: // RST5
        case 0xD6: // RST6
        case 0xD7: // RST7
            // TODO: data escape check
            $huffmanData = $this->huffmanData;
            echo "\t(Huffman Encoded Data len:)".strlen($huffmanData)."\n";
            break;
        case 0xEE: // APP14 (APPE) Adobe Color transform
            $APP14_id = $this->ID;
            $APP14_version = $this->Version;
            $APP14_flag0 = $this->Flag0;
            $APP14_flag1 = $this->Flag1;
            $APP14_colTr =  $this->ColorTransform;
            $APP14_colTrStr = ["RGB or CMYK", "YCbCr", "YCCK"][$APP14_colTr];
            printf("\tID:%d Version:%d Flag0:0x%04x Flag1:0x%04x ColorTransform:%d(%s)\n",
                   $APP14_id, $APP14_version, $APP14_flag0, $APP14_flag1, $APP14_colTr, $APP14_colTrStr);
            break;
        }
    }
    function build($bit) {
        $bit->putUI8(0xFF);
        assert(isset($this->marker));
        $marker = $this->marker;
        $bit->putUI8($marker);
        switch ($marker) {
        case 0xD8: // SOI (Start of Image)
            break;
        case 0xD9: // EOI (End of Image)
            break;
        case 0xD0: case 0xD1: case 0xD2: case 0xD3: // RST
        case 0xD4: case 0xD5: case 0xD6: case 0xD7: // RST
        case 0xDA: // SOS
        case 0xD0: case 0xD1: case 0xD2: case 0xD3: // RST
        case 0xD4: case 0xD5: case 0xD6: case 0xD7: // RST
        case 0xDD: // DRI
            if (is_null($this->data)) {
                $this->_buildChunkDetail();
            }
            $data = $this->data;
            $bit->putData($data);
            break;
        default:
            if (is_null($this->data)) {
                $this->_buildChunkDetail();
            }
            $data = $this->data;
            $length = strlen($data);
            $bit->putUI16BE($length + 2);
            $bit->putData($data);
            break;
        }
    }
    function _buildChunkDetail() {
        $bit = new IO_Bit();
        switch ($this->marker) {
        case 0xD8: // SOI
        case 0xD9: // EOI
            // nothing to do
            break;
        case 0xE0: // APP0
            assert(strlen($this->identifier) === 5);
            $bit->putData($this->identifier, 5);
            if ($this->identifier === "JFIF\0") {
                list($version1, $version2) = sscanf($this->version, "%d.%02d");
                $bit->putUI8($version1);
                $bit->putUI8($version2);
                $bit->putUI8($this->U);
                $bit->putUI16BE($this->Xd);
                $bit->putUI16BE($this->Yd);
                $bit->putUI8($this->Xt );
                $bit->putUI8($this->Yt );
                $bit->putData($this->RGB);
            } else {
                $bit->putUI8($this->extension_code);
                $bit->putData($this->extension_data);
            }
            break;
        case 0xC0: // SOF0
        case 0xC1: // SOF1
        case 0xC2: // SOF2
        case 0xC3: // SOF3
        case 0xC5: // SOF5
        case 0xC6: // SOF6
        case 0xC7: // SOF7
            $bit->putUI8($this->P);
            $bit->putUI16BE($this->Y);
            $bit->putUI16BE($this->X);
            $SOF_Nf = $this->Nf;
            $SOF_C  = $this->C;
            $SOF_H  = $this->H;
            $SOF_V  = $this->V;
            $SOF_Tq = $this->Tq;
            assert($SOF_Nf === count($SOF_C));
            assert($SOF_Nf === count($SOF_H));
            assert($SOF_Nf === count($SOF_V));
            assert($SOF_Nf === count($SOF_Tq));
            $bit->putUI8($SOF_Nf);
            for ($i = 0 ; $i < $SOF_Nf; $i++) {
                $bit->putUI8($SOF_C[$i]);
                $bit->putUIBits($SOF_H[$i], 4);
                $bit->putUIBits($SOF_V[$i], 4);
                $bit->putUI8($SOF_Tq[$i]);
            }
            break;
        case 0xDB: // DQT
            $DQT_Pq = $this->Pq;
            $DQT_Tq = $this->Tq;
            $DQT_Q = $this->Q;
            foreach ($DQT_Pq as $idx => $Pq) {
                $Tq = $this->Tq[$idx];
                $Q = $this->Q[$idx];
                assert(count($Q) === 64);
                $bit->putUIBits($Pq, 4);
                $bit->putUIBits($Tq, 4);
                if ($Pq === 0) {
                    for ($k = 0 ; $k < 64 ; $k++) {
                        $bit->putUI8($Q[$k]);
                    }
                } else {
                    for ($k = 0 ; $k < 64 ; $k++) {
                        $bit->putUI16BE($Q[$k]);
                    }
                }
            }
            break;
        case 0xC4: // DHT
            $DHT_Tc = $this->Tc;
            $DHT_Th = $this->Th;
            $DHT_L = $this->L;
            $DHT_V = $this->V;
            foreach ($DHT_Tc as $idx => $Tc) {
                $Th = $DHT_Th[$idx];
                $L  = $DHT_L[$idx];
                $V  = $DHT_V[$idx];
                assert(count($L) === 16);
                $bit->putUIBits($Tc, 4);
                $bit->putUIBits($Th, 4);
                for ($i = 0 ; $i < 16 ; $i++) {
                    $bit->putUI8($L[$i]);
                }
                for ($i = 0 ; $i < 16 ; $i++) {
                    $Li = $L[$i];
                    if ($Li > 0) {
                        $Vi = $V[$i];
                        assert(count($Vi) === $Li);
                        for ($j = 0 ; $j < $Li ; $j++) {
                            $bit->putUI8($Vi[$j]);
                        }
                    }
                }
            }
            break;
        case 0xDA: // SOS
            $bit->putUI16BE($this->Ls);
            $SOS_Ns = $this->Ns;
            $SOS_Cs = $this->Cs;
            $SOS_Td = $this->Td;
            $SOS_Ta = $this->Ta;
            $bit->putUI8($SOS_Ns);
            assert(count($SOS_Cs) === $SOS_Ns);
            assert(count($SOS_Td) === $SOS_Ns);
            assert(count($SOS_Ta) === $SOS_Ns);
            for ($i = 0 ; $i < $SOS_Ns ; $i++) {
                $bit->putUI8($SOS_Cs[$i]);
                $bit->putUIBits($SOS_Td[$i], 4);
                $bit->putUIBits($SOS_Ta[$i], 4);
            }
            $bit->putUI8($this->Ss);
            $bit->putUI8($this->Se);
            $bit->putUIBits($this->Ah, 4);
            $bit->putUIBits($this->Al, 4);
            // TODO: data escape check
            $bit->putData($this->huffmanData);
            break;
        case 0xDD: // DRI
            $bit->putUI16BE($this->Lr);
            $bit->putUI16BE($this->Ri);
            break;
        case 0xD0: // RST0
        case 0xD1: // RST1
        case 0xD2: // RST2
        case 0xD3: // RST3
        case 0xD4: // RST4
        case 0xD5: // RST5
        case 0xD6: // RST6
        case 0xD7: // RST7
            // TODO: data escape check
            $bit->putData($this->huffmanData);
            break;
        case 0xEE: // APP14 (APPE) Adobe Color transform
            // https://www.itu.int/rec/T-REC-T.872-201206-I
            assert(strlen($this->ID) === 5);
            $bit->putData($this->ID , 5);
            $bit->putUI16BE($this->Version);
            $bit->putUI16BE($this->Flag0);
            $bit->putUI16BE($this->Flag1);
            $bit->putUI8($this->ColorTransform);
        }
        $this->data = $bit->output();
    }
}
