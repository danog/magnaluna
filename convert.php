<?php

if (!file_exists('madeline.php')) {
    copy('https://phar.madelineproto.xyz/madeline.php', 'madeline.php');
}
include 'madeline.php';

use danog\MadelineProto\LocalFile;

if ($argc < 3) {
    die("Usage: {$argv[0]} input output.ogg".PHP_EOL);
}

\danog\MadelineProto\Ogg::convert(new LocalFile($argv[1]), new LocalFile($argv[2]));