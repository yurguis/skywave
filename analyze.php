<?php
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

use Skywave\Parser;
use Skywave\Report\ConsoleRenderer as MpegTsRenderer;

require_once __DIR__ . '/vendor/autoload.php';

if (count($argv) !== 2) {
    fwrite(STDERR, "Usage: analyze <file-or-url>\n");
    exit(1);
}

$path       = $argv[1];
$fileHandle = fopen($path, 'rb');

if ($fileHandle === false) {
    fwrite(STDERR, "Unable to open: $path\n");
    exit(1);
}

// Peek at the first 4 bytes to be sure this is a transport stream before reading it all.
$head = '';

while (strlen($head) < 4 && !feof($fileHandle)) {
    $chunk = fread($fileHandle, 4 - strlen($head));

    if ($chunk === false || $chunk === '') {
        break;
    }

    $head .= $chunk;
}

if (strlen($head) < 4) {
    fwrite(STDERR, "Input is too short to identify a format (< 4 bytes).\n");
    exit(1);
}

if ($head[0] !== "\x47") {
    fwrite(STDERR, "Input does not look like MPEG-TS.\n");
    fwrite(STDERR, '  First 4 bytes (hex): ' . bin2hex($head) . "\n\n");
    fwrite(STDERR, "Expected 0x47, the transport stream sync byte.\n");
    exit(1);
}

runMpegTs($fileHandle, $head, $path);

function runMpegTs($fileHandle, string $head, string $path): void
{
    $parser = new Parser();
    $buffer = $head;

    while (!feof($fileHandle)) {
        $chunk = fread($fileHandle, 188 - strlen($buffer));

        if ($chunk === false) {
            break;
        }

        $buffer .= $chunk;

        if (strlen($buffer) < 188) {
            continue;
        }

        if ($parser->analyze($buffer) === Parser::RETURN_TYPE_DONE) {
            break;
        }

        $buffer = '';
    }

    fclose($fileHandle);

    echo (new MpegTsRenderer())->render($parser, basename($path));
}
