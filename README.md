IO_JPEG
=======

English version: [README.en.md](README.en.md)

JPEG バイナリをマーカー区切りのセグメント (チャンク) 単位で解釈・ダンプ・編集する為のライブラリです。
メタデータの削除、セグメントの分割と連結、Adobe マーカーや色成分 ID の書き換えなどのツールが付いています。
IO_Bit が必要です。利用に制限はかけません。コピーも改変もご自由にどうぞ。MIT ライセンスにしました。
自分の環境だと動かないとか、機能が欲しいとかいった要望があれば連絡下さい。

# License

MIT License

# Install

```
% composer require yoya/io_jpeg
```

# Usage

- jpegdump.php (セグメント構造のダンプ)

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

  - `-D`: 中身の解釈を省き、セグメントの一覧だけ出す
  - `-h`: セグメントごとに 16 進ダンプを付ける
  - `-S`: SOS 以降を RST で区切らず、1 つのセグメントとして読む

- jpegdelmeta.php (メタデータの削除)

```
% php vendor/yoya/io_jpeg/sample/jpegdelmeta.php -f input.jpg > output.jpg
% php vendor/yoya/io_jpeg/sample/jpegdump.php -D -f output.jpg
SOI:
APP0: length=16
SOF0: length=17
DHT: length=31
(omit)
```

- jpegchunk.php / jpegcat.php (セグメントへの分割と連結)

```
% php vendor/yoya/io_jpeg/sample/jpegchunk.php -s -f input.jpg
% ls
00_SOI.jc	09_DHT.jc	18_RST3.jc	27_RST4.jc	36_RST5.jc
01_APP0.jc	10_DHT.jc	19_RST4.jc	28_RST5.jc	37_RST6.jc
02_APP1.jc	11_DQT.jc	20_RST5.jc	29_RST6.jc	38_RST7.jc
(omit)
% php vendor/yoya/io_jpeg/sample/jpegcat.php *.jc > output.jpg   # 元と同じバイト列になる
```

- jpegcolor.php (色に関わるマーカーの表示・書き換え)

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

- PHP から使う

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

// APP1 (Exif, XMP) を消して書き出す
$jpeg->removeChunk(0xE1);
ob_start();
$jpeg->build();               // build() は結果を echo する
file_put_contents('output.jpg', ob_get_clean());
```

# 設計概要

| クラス | 役割 |
|---|---|
| `IO_JPEG` | JPEG 全体。`parse()` でセグメント (`IO_JPEG_Chunk`) の配列 `_jpegChunk` を作り、`dump()` / `build()` する |
| `IO_JPEG_Chunk` | セグメント 1 個。マーカー (`marker`)、中身のバイト列 (`data`)、長さ (`length`) を持ち、必要になったときだけ中身を解釈する |
| `IO_JPEG_Editor` | `IO_JPEG` の拡張。セグメントの作り直し (`rebuild`)、挿入・置き換え (`updateOrAppendChunkBetween`)、削除 (`removeChunk`) |
| `IO_Bit` (別パッケージ) | バイト・ビット単位の読み書き |

- `parse($jpegdata, $eoiFinish = true, $sosScan = true)`: `$eoiFinish` なら EOI で読むのを止める。
  `$sosScan` なら SOS 以降の圧縮データを RST0〜7 ごとに別のセグメントにする。偽なら SOS から末尾 (EOI の手前) までを 1 つにする。
- `data` は長さフィールドを除いた中身。SOS・RSTn は長さを持たないものとして扱い、次のマーカーの手前までを `data` にする。
- マーカーの前の埋め草 (0xFF の繰り返し) は、その数を `fillLength` に持ち、build で書き戻す。dump では `fill=N` と出る。
- 遅延解釈: `_parseChunkDetail()` で、APP0 (JFIF)、APP14 (Adobe)、SOF0〜7、DHT、DQT、SOS、DRI の中身をプロパティ (`X`, `Y`, `Nf`, `C`, `Q` など) に展開する。
  `dump(['detail' => true])` はこれを使う。
- build: `data` が残っていればそのまま書く。`data` を `null` にしたセグメントだけ、プロパティから中身を作り直す。
  `parse()` → `build()` は元と同じバイト列になる。
- `rebuild()` は、作り直せる種類 (上の解釈できる種類と RSTn) のセグメントだけプロパティに展開して `data` を `null` にする。
  それ以外 (APP1 などのメタデータや COM) はバイト列のまま残す。

# 機能

| 機能 | サンプル (`sample/`) |
|---|---|
| 構造のダンプ | `jpegdump.php` |
| メタデータの削除 (SOI, APP0, DQT, SOFn, DHT, DRI, SOS, RSTn, EOI 以外を消す。APP14 や ICC プロファイルの APP2 も消える) | `jpegdelmeta.php` |
| セグメントごとのファイルへの分割 (`-c` で先頭からの累積) | `jpegchunk.php` |
| 分割したセグメントの連結 | `jpegcat.php` |
| JFIF (APP0)・Adobe (APP14) マーカーの追加・削除、色成分 ID の書き換え | `jpegcolor.php` |
| 全セグメントの作り直し | `jpegrebuild.php` |
| SOS の圧縮データ中の、0x00 が続かない 0xFF に 0x00 を補う | `jpegrepair.php` |

# 既知の制限

- `build()` は結果を返さずに echo する。
- SOF9〜15 (算術符号) は中身を解釈しない (バイト列のままなら読み書きできる)。
- 同じオブジェクトで `parse()` を 2 回呼ぶと、セグメントの配列が後ろに足される。

# Test

```
% php tests/run.php
```

# Reference

- ITU-T T.81 (JPEG): https://www.itu.int/rec/T-REC-T.81
- ITU-T T.84 (JPEG extensions, Table B.1 Marker code assignments): https://www.itu.int/rec/T-REC-T.84-199607-I/en
- ITU-T T.872 (APP14 Adobe marker): https://www.itu.int/rec/T-REC-T.872-201206-I
- http://pwiki.awm.jp/~yoya/?JPEG

## IO_Bit の利用箇所

マーカーをバイト単位で走査し、セグメントの長さは `getUI16BE()` で読み取ります。

[IO_Bit Wiki](https://github.com/yoya/IO_Bit/wiki)
