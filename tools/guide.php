<?php

declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

/**
 * Program guide collector.
 *
 *   php tools/guide.php scan <ip> [--map=us-bcast]   find the channels a device receives
 *   php tools/guide.php collect <ip> [--map=...]     read guide data from every channel
 *   php tools/guide.php run [--interval=240]         keep every known device's guide fresh
 *   php tools/guide.php show [<ip>] [--hours=6]      print what is stored
 *
 * Options: --seconds=30 longest read per channel, --scan-days=7 how often "run" rescans.
 * "run" covers devices found by broadcast discovery, HDHOMERUN_DEVICES, and devices that
 * already have a lineup in the guide (e.g. scanned from the web UI). The database
 * is GUIDE_DB (default data/guide.sqlite). Only idle tuners are used.
 */

use Skywave\Dvr\TunerReservations;
use Skywave\Guide\ChannelLogos;
use Skywave\Guide\GuideCollector;
use Skywave\Guide\GuideJobs;
use Skywave\Guide\GuideStore;
use Skywave\Hdhomerun\Device;
use Skywave\Hdhomerun\Discovery;
use Skywave\Hdhomerun\Exception\HdhomerunException;

require_once __DIR__ . '/../vendor/autoload.php';

const USAGE = <<<'TXT'
Usage:
  php tools/guide.php scan <ip> [--map=us-bcast]
  php tools/guide.php collect <ip> [--map=us-bcast]
  php tools/guide.php run [--interval=240] [--scan-days=7]
  php tools/guide.php logos <ip> [--force]
  php tools/guide.php show [<ip>] [--hours=6]

TXT;

$positional = [];
$options    = [];

foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--([a-z-]+)=(.*)$/', $argument, $match)) {
        $options[$match[1]] = $match[2];
    } else {
        $positional[] = $argument;
    }
}

// --log keeps a copy where the page can read it: a service's own output lives in the
// container's stdout, which the browser has no way to reach.
$logFile = $options['log'] ?? null;

$log = static function (string $line) use ($logFile): void {
    $entry = date('Y-m-d H:i:s') . " $line\n";
    fwrite(STDOUT, $entry);

    if ($logFile !== null) {
        @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
    }
};
$store        = GuideStore::fromEnvironment();
$jobs         = GuideJobs::fromEnvironment();
$reservations = TunerReservations::fromEnvironment();
$collector    = new GuideCollector($store, (int) ($options['seconds'] ?? 30), $log);

switch ($positional[0] ?? '') {
    case 'scan':
    case 'collect':
        if (!isset($positional[1])) {
            fwrite(STDERR, USAGE);
            exit(1);
        }

        $command = $positional[0];
        $host    = $positional[1];

        $status = withDeviceLock($jobs, $host, $log, static function () use ($collector, $reservations, $command, $host, $options): void {
            $device = Device::at($host);
            $map    = $options['map'] ?? preferredChannelMap($device);

            $collector->skipTunersUnless(fn (int $index): bool => !$reservations->isReserved($host, $index));

            $command === 'scan' ? $collector->scan($device, $map) : $collector->collect($device, $map);
        });

        // A scan is the moment the channels change, so it is the moment to look for their
        // logos. Whether that works says nothing about whether the scan did.
        if ($status === 0 && $command === 'scan') {
            refreshLogos($host, $log);
        }

        exit($status);

    case 'run':
        $interval = max(5, (int) ($options['interval'] ?? 240));
        $scanDays = max(1, (int) ($options['scan-days'] ?? 7));

        while (true) {
            $hosts  = knownHosts($store);
            $failed = false;

            if ($hosts === []) {
                $log('No devices found; set HDHOMERUN_DEVICES if discovery cannot reach them');
            }

            foreach ($hosts as $host) {
                $failed = withDeviceLock($jobs, $host, $log, static function () use ($collector, $reservations, $store, $host, $options, $scanDays): void {
                    $device   = Device::at($host);
                    $map      = $options['map'] ?? preferredChannelMap($device);
                    $lastScan = $store->getRecentRuns(1, $host, 'scan')[0] ?? null;

                    // A tuner holding a recording is off limits, however idle it looks.
                    $collector->skipTunersUnless(fn (int $index): bool => !$reservations->isReserved($host, $index));

                    if ($store->getLineup($host) === [] || $lastScan === null || $lastScan['startedAt'] < time() - $scanDays * 86400) {
                        $collector->scan($device, $map);
                    }

                    $collector->collect($device, $map);
                }) !== 0 || $failed;
            }

            // A device that was unreachable (e.g. still starting up) is retried in a minute
            // instead of leaving the guide stale for a whole interval. With no devices at all,
            // look again every 15 minutes; a scan from the web UI also adds its device.
            if ($failed) {
                $wait = min($interval, 1);
                $log('Some devices failed; retrying in 1 minute');
            } elseif ($hosts === []) {
                $wait = min($interval, 15);
                $log("Looking for devices again in $wait minutes");
            } else {
                $wait = $interval;
                $log("Next update in $interval minutes");
            }

            sleep($wait * 60);
        }

        // no break
    case 'logos':
        if (!isset($positional[1])) {
            fwrite(STDERR, USAGE);
            exit(1);
        }

        exit(refreshLogos($positional[1], $log, isset($options['force'])));

    case 'show':
        showGuide($store, $positional[1] ?? null, max(1, (int) ($options['hours'] ?? 6)));
        exit(0);

    default:
        fwrite(STDERR, USAGE);
        exit(1);
}

/**
 * Fetch the station logos for a device's channels, once, from its maker's service.
 *
 * The only part of this application that wants the internet; when it cannot have it, the
 * channels simply show their names.
 */
function refreshLogos(string $host, callable $log, bool $force = false): int
{
    $logos = ChannelLogos::fromEnvironment();

    try {
        $device = Device::at($host)->getDiscovered();

        if ($device === null) {
            $log("No logos for $host: discovery did not answer, so there is no token to ask with");

            return 1;
        }

        $totals = $logos->refresh($device, $force);
        $log(sprintf(
            'Logos: %d fetched, %d already here, %d failed, of %d channels',
            $totals['fetched'],
            $totals['skipped'],
            $totals['failed'],
            $totals['channels']
        ));

        return 0;
    } catch (HdhomerunException | RuntimeException $e) {
        $log('Logos: ' . $e->getMessage());

        return 1;
    }
}

/**
 * Run a job holding the device's lock, so the web UI and this tool never overlap.
 */
function withDeviceLock(GuideJobs $jobs, string $host, callable $log, callable $job): int
{
    // A short wait covers the web UI checking the lock just as this job starts.
    $lock = $jobs->acquire($host, 2.0);

    if ($lock === null) {
        $log("A guide job is already running for $host");

        return 1;
    }

    try {
        $job();

        return 0;
    } catch (HdhomerunException | RuntimeException | InvalidArgumentException $e) {
        $log("Failed for $host: {$e->getMessage()}");

        return 1;
    } finally {
        $jobs->release($lock);
    }
}

/**
 * The channel map the device's first tuner uses, which is what its owner set up.
 */
function preferredChannelMap(Device $device): string
{
    try {
        return $device->getTuner(0)->getChannelMap();
    } catch (HdhomerunException $e) {
        return 'us-bcast';
    }
}

/**
 * @return string[]
 */
function knownHosts(GuideStore $store): array
{
    $hosts = array_map(fn ($device) => $device->getIp(), (new Discovery())->findDevices());
    $hosts = array_merge($hosts, array_column($store->getLineup(), 'device'));

    foreach (explode(',', (string) getenv('HDHOMERUN_DEVICES')) as $host) {
        if (trim($host) !== '') {
            $hosts[] = trim($host);
        }
    }

    return array_values(array_unique($hosts));
}

function showGuide(GuideStore $store, ?string $host, int $hours): void
{
    $range = $store->getDataRange($host);

    if ($range['first'] === null) {
        echo "No guide data yet. Run: php tools/guide.php collect <ip>\n";

        return;
    }

    // Start now, or at the beginning of the data when it is all in the past (a recording).
    $from = $range['last'] < time() ? $range['first'] : time();
    $from = intdiv($from, 1800) * 1800;
    $to   = $from + $hours * 3600;

    printf("Guide %s - %s\n", date('D M j H:i', $from), date('D M j H:i', $to));

    foreach ($store->getGuide($from, $to, $host) as $channel) {
        printf("\n%s %s  (%s, physical %d, program %d)\n", $channel['virtual'], $channel['name'], $channel['device'], $channel['physical'], $channel['program']);

        foreach ($channel['events'] as $event) {
            printf(
                "  %s-%s  %s%s\n",
                date('H:i', $event['start']),
                date('H:i', $event['start'] + $event['duration']),
                $event['title'],
                $event['rating'] === null ? '' : " [{$event['rating']}]"
            );
        }
    }
}
