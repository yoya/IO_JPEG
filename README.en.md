IO_JPEG
=======

日本語版: [README.md](README.md)

A library for parsing, dumping and editing JPEG binaries segment (chunk) by segment, split at markers.
It comes with tools to remove metadata, split a JPEG into segments and join them again, and rewrite the Adobe marker and the color component IDs.
It requires IO_Bit. Not limited in use, free to copy and modify under the MIT license.
Please contact me if it does not work in your environment or if you want new features.

# License

MIT License

# Install

```
% composer require yoya/io_jpeg
```

# Usage

- jpegdump.php (segment structure dump)

```
% php vendor/yoya/io_jpeg/sample/jpegdump.php
Usage: php jpegdump.php [-h] [-D] [-S] -f <jpegfile>
% php vendor/yoya/io_jpeg/sample/jpegdump.php -f input.jpg
SOI:
	Start Of Image
APP0: length=16
	identifier:JFIF 
	verison:1.01
	U:1 Xd:72 Yd:72 Xt:0 Yt:0 RGB(size:0)
APP1: length=354
APP1: length=2580
APP13: length=56
APP2: length=3160
SOF0: length=17
	(Baseline DCT)
	P:8 Y:480 X:640 Nf:3
	[i=1]: C:1 H:2 V:2 Tq:0
	[i=2]: C:2 H:1 V:1 Tq:1
	[i=3]: C:3 H:1 V:1 Tq:1
DHT: length=31
	Tc:0(DC) Th:0
	Li:0 1 5 1 1 1 1 1 1 0 0 0 0 0 0 0
	Vij[i=1]:00
	Vij[i=2]:01 02 03 04 05
(omit)
DQT: length=67
	Pq:0(8-bit) Tq:0
	Q[k=0x00]:02 02 02 02 02 02 03 02
	Q[k=0x08]:02 03 05 03 03 03 05 06
(omit)
DRI: length=4
	Lr:4 Ri:40
SOS: length=(null)
	Ls:12 Ns:3
	[i=1] Cs:1 Td:0 Ta:0
	[i=2] Cs:2 Td:1 Ta:1
	[i=3] Cs:3 Td:1 Ta:1
	Ss:0 Se:63 Ah:0 Al:0
	(Huffman Encoded Data len:2121)
RST0: length=(null)
	(Huffman Encoded Data len:)2081
(omit)
EOI:
	End Of Image
```

  - `-D`: skip decoding the contents and list the segments only
  - `-h`: add a hex dump to each segment
  - `-S`: read everything from SOS on as one segment, without splitting at RST

- jpegdelmeta.php (remove metadata)

```
% php vendor/yoya/io_jpeg/sample/jpegdelmeta.php -f input.jpg > output.jpg
% php vendor/yoya/io_jpeg/sample/jpegdump.php -D -f output.jpg
SOI:
APP0: length=16
SOF0: length=17
DHT: length=31
(omit)
```

- jpegchunk.php / jpegcat.php (split into segments and join them)

```
% php vendor/yoya/io_jpeg/sample/jpegchunk.php -s -f input.jpg
% ls
00_SOI.jc	09_DHT.jc	18_RST3.jc	27_RST4.jc	36_RST5.jc
01_APP0.jc	10_DHT.jc	19_RST4.jc	28_RST5.jc	37_RST6.jc
02_APP1.jc	11_DQT.jc	20_RST5.jc	29_RST6.jc	38_RST7.jc
(omit)
% php vendor/yoya/io_jpeg/sample/jpegcat.php *.jc > output.jpg   # same bytes as the input
```

- jpegcolor.php (show and rewrite the color-related markers)

```
% php vendor/yoya/io_jpeg/sample/jpegcolor.php -f input.jpg
APP0:
	identifier:JFIF 
	verison:1.01
	U:1 Xd:72 Yd:72 Xt:0 Yt:0 RGB(size:0)
SOF0:
	ComponentID: 1 2 3 
% php vendor/yoya/io_jpeg/sample/jpegcolor.php -f input.jpg -a 0 > output.jpg
% php vendor/yoya/io_jpeg/sample/jpegcolor.php -f output.jpg
APP0:
	identifier:JFIF 
	verison:1.01
	U:1 Xd:72 Yd:72 Xt:0 Yt:0 RGB(size:0)
APP14:
	ID:Adobe Version:100 Flag0:0x4000 Flag1:0x0000 ColorTransform:0(RGB or CMYK)
SOF0:
	ComponentID: 1 2 3 
```

- From PHP

```php
require_once 'IO/JPEG/Editor.php';

$jpeg = new IO_JPEG_Editor();
$jpeg->parse(file_get_contents('input.jpg'));

foreach ($jpeg->_jpegChunk as $chunk) {
    if ($chunk->marker === 0xC0) {  // SOF0
        $chunk->_parseChunkDetail();
        echo "{$chunk->X}x{$chunk->Y} components:{$chunk->Nf}\n";  // 640x480 components:3
    }
}

// remove APP1 (Exif, XMP) and write the result
$jpeg->removeChunk(0xE1);
ob_start();
$jpeg->build();               // build() echoes the JPEG
file_put_contents('output.jpg', ob_get_clean());
```

# Design

| Class | Role |
|---|---|
| `IO_JPEG` | The whole JPEG. `parse()` builds `_jpegChunk`, an array of segments (`IO_JPEG_Chunk`), and `dump()` / `build()` work on it |
| `IO_JPEG_Chunk` | One segment. It holds the marker (`marker`), the content bytes (`data`) and the length (`length`), and decodes the content only when needed |
| `IO_JPEG_Editor` | Extends `IO_JPEG`. Rebuilds segments (`rebuild`), inserts or replaces one (`updateOrAppendChunkBetween`), removes them (`removeChunk`) |
| `IO_Bit` (separate package) | Byte and bit level reading and writing |

- `parse($jpegdata, $eoiFinish = true, $sosScan = true)`: with `$eoiFinish` it stops at EOI.
  With `$sosScan` the compressed data after SOS is split into separate segments at each RST0-7. Otherwise everything from SOS to the end (before EOI) is one segment.
- `data` is the content without the length field. SOS and RSTn are treated as having no length field, and `data` runs up to the next marker.
- Fill bytes before a marker (repeated 0xFF) are counted in `fillLength` and written back by build. dump shows them as `fill=N`.
- Lazy decoding: `_parseChunkDetail()` expands the content of APP0 (JFIF), APP14 (Adobe), SOF0-7, DHT, DQT, SOS and DRI into properties (`X`, `Y`, `Nf`, `C`, `Q`, ...).
  `dump(['detail' => true])` uses it.
- build: a segment that still has `data` is written as is. Only segments whose `data` is set to `null` are rebuilt from their properties.
  `parse()` → `build()` gives the same bytes as the input.
- `rebuild()` expands and sets `data` to `null` only for the kinds it can rebuild (the decodable kinds above and RSTn).
  Others (metadata such as APP1, and COM) stay as bytes.

# Features

| Feature | Sample (`sample/`) |
|---|---|
| Structure dump | `jpegdump.php` |
| Remove metadata (removes everything except SOI, APP0, DQT, SOFn, DHT, DRI, SOS, RSTn and EOI; APP14 and the ICC profile in APP2 are removed too) | `jpegdelmeta.php` |
| Split into one file per segment (`-c` writes the cumulative data from the start) | `jpegchunk.php` |
| Join split segments | `jpegcat.php` |
| Add or remove the JFIF (APP0) and Adobe (APP14) markers, rewrite the color component IDs | `jpegcolor.php` |
| Rebuild all segments | `jpegrebuild.php` |
| Insert 0x00 after each 0xFF in the SOS compressed data that is not followed by 0x00 | `jpegrepair.php` |

# Known limitations

- `build()` echoes the result instead of returning it.
- `jpegdelmeta.php`, `jpegchunk.php -s` and `jpegrepair.php` do not write the fill bytes before markers.
- The content of SOF9-15 (arithmetic coding) is not decoded (they can be read and written as bytes).
- Calling `parse()` twice on the same object appends to the segment array.

# Test

```
% php tests/run.php
```

# Reference

- ITU-T T.81 (JPEG): https://www.itu.int/rec/T-REC-T.81
- ITU-T T.84 (JPEG extensions, Table B.1 Marker code assignments): https://www.itu.int/rec/T-REC-T.84-199607-I/en
- ITU-T T.872 (APP14 Adobe marker): https://www.itu.int/rec/T-REC-T.872-201206-I
- http://pwiki.awm.jp/~yoya/?JPEG
