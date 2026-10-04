<?php

declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

/**
 * Plays one radio station: a decoder reads the air, ffmpeg turns what it makes into a
 * playlist, and whatever is said about the station is kept beside it.
 *
 *   php tools/radio.php --directory=DIR --ffmpeg=ffmpeg [--rewind=300] [--program=0]
 *                       [--mode=hd|fm|am] [--redsea=/path/to/redsea] -- nrsc5 ...
 *
 * Everything after "--" is the decoder command: nrsc5 for HD Radio, rtlanalog for analog.
 * The mode says which, and on analog FM --redsea names the program that reads the station's
 * name and the song out of the multiplex.
 * The web UI starts this when somebody listens and stops it when the last listener leaves;
 * it is not a service, and nothing is gained by running it by hand except seeing why a
 * station will not play.
 *
 * The session that starts this looks for "ffmpeg" in the command line of the process it
 * started, to be sure the pid is still its own. The --ffmpeg option puts it there.
 */

use Skywave\Radio\Listener;
use Skywave\Radio\Receiver;

require_once dirname(__DIR__) . '/vendor/autoload.php';

const USAGE = <<<TXT
Usage:
  php tools/radio.php --directory=DIR --ffmpeg=ffmpeg [--rewind=300] [--program=0]
                      [--mode=hd|fm|am] [--redsea=PATH] -- nrsc5 ...

TXT;

$arguments = array_slice($argv, 1);
$separator = array_search('--', $arguments, true);
$options   = [];

foreach ($separator === false ? [] : array_slice($arguments, 0, $separator) as $argument) {
    if (preg_match('/^--([a-z-]+)=(.*)$/', $argument, $match)) {
        $options[$match[1]] = $match[2];
    }
}

$receiver  = $separator === false ? [] : array_slice($arguments, $separator + 1);
$directory = $options['directory'] ?? '';

if ($receiver === [] || $directory === '' || !is_dir($directory)) {
    fwrite(STDERR, USAGE);
    exit(1);
}

$program = (int) ($options['program'] ?? 0);

try {
    $mode = Receiver::validateMode($options['mode'] ?? Receiver::MODE_HD);
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$encoder = Listener::encoderArguments($options['ffmpeg'] ?? 'ffmpeg', $directory, max(1, (int) ($options['rewind'] ?? 300)), $mode);

// Only analog FM has anything to read, and only when redsea is there to read it.
$redsea   = $options['redsea'] ?? '';
$metadata = $mode === Receiver::MODE_FM && $redsea !== ''
    ? [$redsea, '-r', (string) Receiver::MPX_RATE, '-i', 'mpx']
    : [];

exit((new Listener($directory, $receiver, $encoder, $program, null, $mode, $metadata))->run());
