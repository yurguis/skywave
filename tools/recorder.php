<?php

declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

/**
 * Runs scheduled recordings.
 *
 *   php tools/recorder.php run [--tick=10]     start and stop recordings as they come due
 *   php tools/recorder.php list                what is scheduled and what has been recorded
 *   php tools/recorder.php stop <id>           stop a running recording, keeping the file
 *
 * Recordings are written to RECORDINGS_DIR and listed in the guide database (GUIDE_DB).
 * The web UI schedules them; this keeps running whether or not anybody has the page open.
 */

use Skywave\Dvr\Recorder;
use Skywave\Dvr\RecordingStore;

require_once dirname(__DIR__) . '/vendor/autoload.php';

const USAGE = <<<TXT
Usage:
  php tools/recorder.php run [--tick=10]
  php tools/recorder.php list
  php tools/recorder.php stop <recording id>

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

$log = static function (string $line): void {
    fwrite(STDOUT, date('Y-m-d H:i:s') . " $line\n");
};

$recorder = Recorder::fromEnvironment($log);
$store    = RecordingStore::fromEnvironment();

switch ($positional[0] ?? '') {
    case 'run':
        $tick = max(2, min(60, (int) ($options['tick'] ?? 10)));
        $log("Watching for recordings in {$recorder->getDirectory()}, every $tick seconds");

        while (true) {
            // A failure of one pass (an unreachable device, a database still locked) must
            // never end the daemon; the next tick tries again.
            try {
                $recorder->tick();
            } catch (Throwable $e) {
                $log('Recorder error: ' . $e->getMessage());
            }

            sleep($tick);
        }

        // no break
    case 'list':
        listRecordings($store);
        exit(0);

    case 'stop':
        if (!isset($positional[1]) || !ctype_digit($positional[1])) {
            fwrite(STDERR, USAGE);
            exit(1);
        }

        exit($recorder->stop((int) $positional[1]) ? 0 : 1);

    default:
        fwrite(STDERR, USAGE);
        exit(1);
}

function listRecordings(RecordingStore $store): void
{
    $schedules = $store->getSchedules();

    echo "Scheduled\n";

    if ($schedules === []) {
        echo "  nothing scheduled\n";
    }

    foreach ($schedules as $schedule) {
        printf(
            "  [%d] %s %s %s · %s · %s%s\n",
            $schedule['id'],
            date('Y-m-d H:i', $schedule['start']),
            $schedule['virtual'],
            $schedule['title'],
            formatDuration($schedule['duration']),
            $schedule['status'],
            $schedule['error'] === null ? '' : " ({$schedule['error']})"
        );
    }

    echo "\nRecorded\n";
    $recordings = $store->getRecordings();

    if ($recordings === []) {
        echo "  nothing recorded yet\n";
    }

    foreach ($recordings as $recording) {
        printf(
            "  [%d] %s %s %s · %.2f GB · %s%s\n",
            $recording['id'],
            date('Y-m-d H:i', $recording['startedAt']),
            $recording['virtual'],
            $recording['title'],
            $recording['bytes'] / 1e9,
            $recording['status'],
            $recording['error'] === null ? '' : " ({$recording['error']})"
        );
    }
}

function formatDuration(int $seconds): string
{
    return $seconds >= 3600
        ? sprintf('%dh %02dm', intdiv($seconds, 3600), intdiv($seconds % 3600, 60))
        : sprintf('%dm', intdiv($seconds, 60));
}
