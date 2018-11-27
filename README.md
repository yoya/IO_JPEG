IO_JPEG
=======

JPEG parser &amp; dumper

# Usage

```
% composer require yoya/io_jpeg
% php vendor/yoya/io_jpeg/sample/jpegdump.php
Usage: php jpegdump.php [-h] [-D] [-S] -f <jpegfile>
% php vendor/yoya/io_jpeg/sample/jpegdump.php -f input.jpg
SOI:
        Start Of Image
APP0: length=16
        identifier:JFIF^@
        verison:1.01
APP2: length=3228
DQT: length=67
        Pq:0(8-bit) Tq:0
        Q[k=0x00]:03 02 02 02 02 02 03 02
        Q[k=0x08]:02 02 03 03 03 03 04 06
        Q[k=0x10]:04 04 04 04 04 08 06 06
        Q[k=0x18]:05 06 09 08 0a 0a 09 08
        Q[k=0x20]:09 09 0a 0c 0f 0c 0a 0b
        Q[k=0x28]:0e 0b 09 09 0d 11 0d 0e
        Q[k=0x30]:0f 10 10 11 10 0a 0c 12
        Q[k=0x38]:13 12 10 13 0f 10 10 10
(omit)
```
