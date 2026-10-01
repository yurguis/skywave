<?php

declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave\Radio;

use RuntimeException;

/**
 * Finds HD Radio stations by trying frequencies, because there is no other way to find them.
 *
 * A television tuner can be asked what a channel carries. A dongle cannot be asked
 * anything: the only test for a station is to point nrsc5 at a frequency and see whether it
 * finds something to lock on to. So a scan is exactly that, once per frequency, up the dial.
 *
 * It is slow for the same reason. A frequency with nothing on it looks, for as long as
 * anyone cares to wait, like one that has not been found yet; each empty one costs the
 * whole wait, and most of the dial is empty.
 */
final class Scanner
{
    /**
     * Where FM stations sit in North America, which is where HD Radio is broadcast: the odd
     * tenths, 200 kHz apart.
     */
    public const FIRST = 87.9;
    public const LAST  = 107.9;
    public const STEP  = 0.2;

    /** @var callable(float): string[] */
    private $command;
    private float $syncSeconds;
    private float $detailSeconds;

    /**
     * @param callable(float): string[] $command       the nrsc5 command that listens to a frequency
     * @param float                     $syncSeconds   how long to wait for a station to be found
     * @param float                     $detailSeconds how long to go on listening once one is, for
     *                                                 its name and the programs it carries
     */
    public function __construct(callable $command, float $syncSeconds = 6.0, float $detailSeconds = 3.0)
    {
        $this->command       = $command;
        $this->syncSeconds   = max(0.1, $syncSeconds);
        $this->detailSeconds = max(0.0, $detailSeconds);
    }

    /**
     * The frequencies between two ends of the dial, both included.
     *
     * @return float[]
     */
    public static function frequencies(float $from = self::FIRST, float $to = self::LAST): array
    {
        $frequencies = [];

        // Counted in steps rather than added up: 87.9 plus 0.2 a hundred times is not 107.9.
        for ($step = 0; ; $step++) {
            $frequency = round(self::FIRST + $step * self::STEP, 1);

            if ($frequency > self::LAST + 0.01 || $frequency > $to + 0.01) {
                break;
            }

            if ($frequency >= $from - 0.01) {
                $frequencies[] = $frequency;
            }
        }

        return $frequencies;
    }

    /**
     * Try every frequency in turn.
     *
     * @param float[]                       $frequencies
     * @param callable(int, float, ?array<string, mixed>): void $onResult called after each one, with
     *                                      the station found there or null
     * @param (callable(): bool)|null       $shouldStop  asked often; true ends the scan early
     */
    public function scan(array $frequencies, callable $onResult, ?callable $shouldStop = null): void
    {
        foreach (array_values($frequencies) as $index => $frequency) {
            if ($shouldStop !== null && $shouldStop()) {
                return;
            }

            $onResult($index, $frequency, $this->probe($frequency, $shouldStop));
        }
    }

    /**
     * Listen to one frequency for long enough to say whether a station is there.
     *
     * @return array{frequency: float, name: ?string, programs: list<array<string, mixed>>, signal: ?float}|null
     * @throws RuntimeException when nrsc5 cannot listen at all: no dongle, no server
     */
    public function probe(float $frequency, ?callable $shouldStop = null): ?array
    {
        $sink = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';

        // The sound is thrown away. Only what nrsc5 says about the station is wanted.
        $process = @proc_open(
            ($this->command)($frequency),
            [0 => ['file', $sink, 'r'], 1 => ['file', $sink, 'w'], 2 => ['pipe', 'w']],
            $pipes
        );

        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start nrsc5');
        }

        stream_set_blocking($pipes[2], false);

        $state    = new StationState();
        $started  = microtime(true);
        $syncedAt = null;
        $pending  = '';

        try {
            while (true) {
                $read   = [$pipes[2]];
                $write  = null;
                $except = null;

                @stream_select($read, $write, $except, 0, 200000);

                $chunk = (string) fread($pipes[2], 65536);
                $lines = explode("\n", $pending . $chunk);

                $pending = (string) array_pop($lines);

                foreach ($lines as $line) {
                    $state->apply($line);
                }

                if ($state->getError() !== null) {
                    throw new RuntimeException(Listener::explain($state->getError()));
                }

                $now = microtime(true);

                if ($syncedAt === null && $state->isSynchronized()) {
                    $syncedAt = $now;
                }

                // Found: stay a little longer, since the name and the list of programs
                // arrive over the next few seconds rather than with the lock.
                if ($syncedAt !== null && $now - $syncedAt >= $this->detailSeconds) {
                    break;
                }

                if ($syncedAt === null && $now - $started >= $this->syncSeconds) {
                    break;
                }

                if (feof($pipes[2]) || ($shouldStop !== null && $shouldStop())) {
                    break;
                }
            }
        } finally {
            self::end($process, $pipes[2]);
        }

        if ($syncedAt === null) {
            return null;
        }

        $station = $state->toArray();

        return [
            'frequency' => $frequency,
            'name'      => $station['station'],
            'programs'  => $station['programs'],
            'signal'    => $station['mer'],
        ];
    }

    /**
     * Stop nrsc5 and wait for it to have gone.
     *
     * The waiting matters: the next frequency needs the dongle, and nrsc5 holds it until
     * the moment it exits.
     *
     * @param resource $process
     * @param resource $log
     */
    private static function end($process, $log): void
    {
        proc_terminate($process);

        for ($waited = 0; $waited < 30 && (proc_get_status($process)['running'] ?? false); $waited++) {
            usleep(100000);
        }

        if (proc_get_status($process)['running'] ?? false) {
            proc_terminate($process, 9);
        }

        fclose($log);
        proc_close($process);
    }
}
