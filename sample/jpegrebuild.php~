<?php

require 'IO/SWF/Editor.php';

if ($argc != 2) {
    echo "Usage: php swfrebuild.php <swf_file>\n";
    echo "ex) php swfrebuild.php test.swf\n";
    exit(1);
}

assert(is_readable($argv[1]));

$swfdata = file_get_contents($argv[1]);

$swf = new IO_SWF_Editor();

$swf->parse($swfdata);

$swf->rebuild();

echo $swf->build();

exit(0);
