<?php

declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

/**
 * Plays one HD Radio program: nrsc5 decodes the station and ffmpeg turns the sound into a
 * playlist, with what nrsc5 says about the station kept beside it.
 *
 *   php tools/radio.php --directory=DIR --ffmpeg=ffmpeg [--rewind=300] [--program=0] -- nrsc5 ...
 *
 * Everything after "--" is the nrsc5 command to run, sending raw audio to standard output.
 * The web UI starts this when somebody listens and stops it when the last listener leaves;
 * it is not a service, and nothing is gained by running it by hand except seeing why a
 * station will not play.
 *
 * The session that starts this looks for "ffmpeg" in the command line of the process it
 * started, to be sure the pid is still its own. The --ffmpeg option puts it there.
 */

use Skywave\Radio\Listener;

require_once dirname(__DIR__) . '/vendor/autoload.php';

const USAGE = <<<TXT
Usage:
  php tools/radio.php --directory=DIR --ffmpeg=ffmpeg [--rewind=300] [--program=0] -- nrsc5 ...

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
$encoder = Listener::encoderArguments($options['ffmpeg'] ?? 'ffmpeg', $directory, max(1, (int) ($options['rewind'] ?? 300)));

exit((new Listener($directory, $receiver, $encoder, $program))->run());
