<?php

/*
  IO_JPEG_Chunk class
  (c) 2020/01/07 yoya@awm.jp
 */

class IO_JPEG_Chunk {
    var $marker;
    var $data;
    var $length;
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
    function _parseChunkDetail(&$chunk) {
        if (! is_null($chunk->data)) {
            $chunkDataBitin = new IO_Bit();
            $chunkDataBitin->input($chunk->data);
        }
        switch ($chunk->marker) {
        case 0xD8: // SOI
        case 0xD9: // EOI
            // nothing to do
            break;
        case 0xE0: // APP0
            $chunk->identifier = $chunkDataBitin->getData(5);
            if ($chunk->identifier === "JFIF\0") {
                $version1 = $chunkDataBitin->getUI8();
                $version2 = $chunkDataBitin->getUI8();
                $chunk->version = sprintf("%d.%02d", $version1, $version2);
            } else {
                $chunk->extension_code = $chunkDataBitin->getUI8();
                $chunk->extension_data = $chunkDataBitin->getDataUntil(false);
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
            $DQT_Pq = $chunkDataBitin->getUIBits(4);
            $DQT_Tq = $chunkDataBitin->getUIBits(4);
            $this->Pq = $DQT_Pq;
            $this->Tq = $DQT_Tq;
            if ($DQT_Pq === 0) {
                for ($k = 0 ; $k < 64 ; $k++) {
                    $DQT_Q[$k] = $chunkDataBitin->getUI8();
                }
            } else {
                for ($k = 0 ; $k < 64 ; $k++) {
                    $DQT_Q[$k] = $chunkDataBitin->getUI16BE();
                }
            }
            $this->Q = $DQT_Q;
            break;
        case 0xC4: // DHT
            $this->Tc = $chunkDataBitin->getUIBits(4);
            $this->Th = $chunkDataBitin->getUIBits(4);
            $DHT_L = Array();
            for ($i = 0 ; $i < 16 ; $i++) {
                $DHT_L[$i] = $chunkDataBitin->getUI8();
            }
            $this->L = $DHT_L;
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
            $this->V = $DHT_V;
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
            break;
        case 0xDD: // DRI
            $this->Lr = $chunkDataBitin->getUI16BE();
            $this->Ri = $chunkDataBitin->getUI16BE();
            break;
        case 0xEE: // APP13 (APPE) Adobe Color transform
            // https://www.itu.int/rec/T-REC-T.872-201206-I
            $this->ID = $chunkDataBitin->getData(5);
            $this->Version = $chunkDataBitin->getUI16BE();
            $this->Flag0 = $chunkDataBitin->getUI16BE();
            $this->Flag1 = $chunkDataBitin->getUI16BE();
            $this->ColorTransform = $chunkDataBitin->getUI8();
        }
    }
    function dump($opts = []) {
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
            $this->dumpChunkDetail();
        }
        if ($opts['hexdump']) {
            if (is_null($this->data)) {
                $bitin->hexdump($this->startOffset, 2);
            } else {
                if (is_null($this->length)) {
                    $length = 2 + strlen($this->dataa);
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
            $DQT_Pq_str = ($DQT_Pq===0)?"8-bit":(($DQT_Pq===1)?"16-bit":"Unknown");
            $DQT_Tq = $this->Tq;
            echo "\tPq:$DQT_Pq($DQT_Pq_str) Tq:$DQT_Tq\n";
            $DQT_Q = $this->Q;
            for ($k = 0 ; $k < 64 ; $k+= 8) {
                $DQT_Q_k8 = array_slice($DQT_Q, $k, 8);
                foreach ($DQT_Q_k8 as $k2 => &$v2) {
                    $v2 = sprintf("%02x", $v2);
                }
                printf("\tQ[k=0x%02x]:", $k);
                echo join(' ', $DQT_Q_k8)."\n";
            }
            break;
        case 0xC4: // DHT
            $DHT_Tc = $this->Tc;
            $DHT_Tc_str = ($DHT_Tc===0)?"DC":(($DHT_Tc===1)?"AC":"Unknown");
            $DHT_Th = $this->Th;
            $DHT_L = $this->L;
            $DHT_V = $this->V;
            echo "\tTc:$DHT_Tc($DHT_Tc_str) Th:$DHT_Th\n";
            echo "\tLi:".join(" ", $DHT_L)."\n";
            foreach ($DHT_V as $i => $DHT_Vi) {
                foreach ($DHT_Vi as $k => &$v) {
                    $v = sprintf("%02x", $v);
                }
                echo "\tVij[i=$i]:".join(" ", $DHT_Vi)."\n";
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
            echo "\tSs:$SOS_Ss Se:$SOS_Se Ah:$SOS_Ah Al:$SOS_Al\n";
            echo "\t(Huffman Encoded Data)\n";
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
            echo "\t(Huffman Encoded Data)\n";
            break;
        case 0xEE: // APP13 (APPE) Adobe Color transform
            $APP13_id = $this->ID;
            $APP13_version = $this->Version;
            $APP13_flag0 = $this->Flag0;
            $APP13_flag1 = $this->Flag1;
            $APP13_colTr =  $this->ColorTransform;
            $APP13_colTrStr = ["RGB or CMYK", "YCbCr", "YCCK"][$APP13_colTr];
            printf("\tID:%d Version:%d Flag0:0x%04x Flag1:0x%04x ColorTransform:%d(%s)\n",
                   $APP13_id, $APP13_version, $APP13_flag0, $APP13_flag1, $APP13_colTr, $APP13_colTrStr);
            break;
        }
    }
}
