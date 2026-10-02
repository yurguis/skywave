<?php

declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

/**
 * Scans the FM band for HD Radio stations and keeps the ones it finds.
 *
 *   php tools/radio-scan.php [--from=87.9] [--to=107.9] [--seconds=6] [--directory=DIR]
 *
 * Points nrsc5 at each frequency in turn and waits --seconds for it to find a station. The
 * whole band is 101 frequencies, most of them empty, so a full scan takes about ten
 * minutes; --from and --to narrow it. RADIO_SCAN_SECONDS sets the wait when --seconds does
 * not: longer finds weaker stations, shorter gets through the empty ones faster.
 *
 * The dongle comes from the same settings the web UI uses (RADIO_RTL_TCP or RADIO_DEVICE),
 * and the stations go into the guide database (GUIDE_DB), where the page lists them. The
 * page starts this itself; run by hand it does the same thing and prints as it goes.
 *
 * Nothing else can use the dongle while this runs, and this cannot run while something
 * else is using it.
 */

use Skywave\Radio\Receiver;
use Skywave\Radio\ScanJobs;
use Skywave\Radio\Scanner;
use Skywave\Radio\StationStore;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$options = [];

foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--([a-z-]+)=(.*)$/', $argument, $match)) {
        $options[$match[1]] = $match[2];
    }
}

$receiver = Receiver::fromEnvironment();

if (!$receiver->isConfigured()) {
    fwrite(STDERR, "HD Radio is not enabled: set RADIO_RTL_TCP or RADIO_DEVICE\n");
    exit(1);
}

if ($receiver->binary() === null) {
    fwrite(STDERR, $receiver->whyMissing() . "\n");
    exit(1);
}

$jobs = isset($options['directory'])
    ? new ScanJobs($options['directory'], __FILE__)
    : ScanJobs::fromEnvironment();

$lock = $jobs->acquire();

if ($lock === null) {
    fwrite(STDERR, "A scan is already running\n");
    exit(1);
}

// Without a database the scan still runs and still reports what it found; it just cannot
// keep it.
try {
    $stations = StationStore::fromEnvironment();
} catch (Throwable $e) {
    fwrite(STDERR, 'Stations will not be kept: ' . $e->getMessage() . "\n");
    $stations = null;
}

$frequencies = Scanner::frequencies(
    (float) ($options['from'] ?? Scanner::FIRST),
    (float) ($options['to'] ?? Scanner::LAST)
);

$progress = [
    'startedAt'  => time(),
    'finishedAt' => null,
    'total'      => count($frequencies),
    'done'       => 0,
    'frequency'  => $frequencies[0] ?? null,
    'found'      => [],
    'stopped'    => false,
    'error'      => null,
];

$jobs->report($progress);

$scanner = new Scanner(
    static fn (float $frequency): array => $receiver->arguments($frequency, 0, null),
    max(2.0, min(30.0, (float) ($options['seconds'] ?? (getenv('RADIO_SCAN_SECONDS') ?: 6))))
);

try {
    $scanner->scan(
        $frequencies,
        static function (int $index, float $frequency, ?array $station) use (&$progress, $frequencies, $jobs, $stations): void {
            if ($station !== null) {
                $progress['found'][] = $station;
                $stations?->save($frequency, $station['name'], $station['programs'], $station['signal']);

                printf("%5.1f  %s\n", $frequency, $station['name'] ?? '(no name given)');
            }

            $progress['done']      = $index + 1;
            $progress['frequency'] = $frequencies[$index + 1] ?? null;

            $jobs->report($progress);
        },
        static fn (): bool => $jobs->stopRequested()
    );
} catch (Throwable $e) {
    // Said last, so it is what the page reports; and recorded, so the page need not go
    // looking for it.
    $progress['error']      = $e->getMessage();
    $progress['finishedAt'] = time();
    $jobs->report($progress);

    echo $e->getMessage(), "\n";
    exit(1);
}

$progress['stopped']    = $jobs->stopRequested();
$progress['finishedAt'] = time();
$progress['frequency']  = null;
$jobs->report($progress);

printf(
    "%s: %d of %d frequencies tried, %d stations found\n",
    $progress['stopped'] ? 'Stopped' : 'Finished',
    $progress['done'],
    $progress['total'],
    count($progress['found'])
);
