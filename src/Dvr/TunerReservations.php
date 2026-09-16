<?php declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave\Dvr;

use RuntimeException;

/**
 * Which tuners are spoken for, so recordings, live playback and guide updates stay out
 * of each other's way.
 *
 * Every process shares one small file in the data directory, locked while it changes, so
 * the web container and the recorder see the same reservations. Entries expire: a
 * recorder that dies gives its tuner back within a couple of minutes instead of holding
 * it forever.
 *
 * This only covers this application. Another client on the network can still retune a
 * tuner, which is what the device's own lock keys are for.
 */
class TunerReservations
{
    /** Long enough to outlive a recorder tick, short enough to recover from a crash. */
    public const DEFAULT_SECONDS = 120;

    private string $file;

    public function __construct(string $directory)
    {
        $this->file = rtrim($directory, '/') . '/reservations.json';
    }

    /**
     * Reservations live next to the guide database (GUIDE_DB), which every container mounts.
     */
    public static function fromEnvironment(): self
    {
        $database = getenv('GUIDE_DB');
        $path     = $database === false || $database === '' ? dirname(__DIR__, 2) . '/data/guide.sqlite' : $database;

        return new self(dirname($path));
    }

    /**
     * Claim a tuner, or null when somebody else holds it.
     */
    public function reserve(string $device, int $tuner, string $label, int $seconds = self::DEFAULT_SECONDS): ?string
    {
        return $this->update(static function (array &$entries) use ($device, $tuner, $label, $seconds): ?string {
            foreach ($entries as $entry) {
                if ($entry['device'] === $device && $entry['tuner'] === $tuner) {
                    return null;
                }
            }

            $token = bin2hex(random_bytes(8));
            $now   = time();

            $entries[$token] = [
                'token'     => $token,
                'device'    => $device,
                'tuner'     => $tuner,
                'label'     => $label,
                'createdAt' => $now,
                'expiresAt' => $now + max(5, $seconds),
            ];

            return $token;
        });
    }

    /**
     * Push an expiry back while the work it covers is still running.
     */
    public function renew(string $token, int $seconds = self::DEFAULT_SECONDS): bool
    {
        return $this->update(static function (array &$entries) use ($token, $seconds): bool {
            if (!isset($entries[$token])) {
                return false;
            }

            $entries[$token]['expiresAt'] = time() + max(5, $seconds);

            return true;
        });
    }

    public function release(string $token): void
    {
        $this->update(static function (array &$entries) use ($token): void {
            unset($entries[$token]);
        });
    }

    /**
     * Give up whatever holds a tuner, however it got there.
     */
    public function releaseTuner(string $device, int $tuner): void
    {
        $this->update(static function (array &$entries) use ($device, $tuner): void {
            foreach ($entries as $token => $entry) {
                if ($entry['device'] === $device && $entry['tuner'] === $tuner) {
                    unset($entries[$token]);
                }
            }
        });
    }

    /**
     * The reservation holding a tuner, if any.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $device, int $tuner): ?array
    {
        foreach ($this->all() as $entry) {
            if ($entry['device'] === $device && $entry['tuner'] === $tuner) {
                return $entry;
            }
        }

        return null;
    }

    public function isReserved(string $device, int $tuner): bool
    {
        return $this->find($device, $tuner) !== null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return array_values(self::current($this->read()));
    }

    /**
     * Read, change and write the reservations while holding the lock.
     *
     * @template T
     * @param callable(array<string, array<string, mixed>>): T $work
     * @return T
     */
    private function update(callable $work)
    {
        $directory = dirname($this->file);

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException("Unable to create $directory");
        }

        $handle = fopen($this->file, 'c+');

        if ($handle === false || !flock($handle, LOCK_EX)) {
            throw new RuntimeException("Unable to lock $this->file");
        }

        try {
            $contents = stream_get_contents($handle);
            $entries  = self::current(self::decode($contents === false ? '' : $contents));
            $result   = $work($entries);

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode(array_values($entries), JSON_PRETTY_PRINT) . "\n");
            fflush($handle);

            return $result;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function read(): array
    {
        $contents = @file_get_contents($this->file);

        return self::decode($contents === false ? '' : $contents);
    }

    /**
     * @param string $json a list of reservations
     * @return array<string, array<string, mixed>> keyed by token
     */
    private static function decode(string $json): array
    {
        $decoded = $json === '' ? [] : json_decode($json, true);
        $entries = [];

        foreach (is_array($decoded) ? $decoded : [] as $entry) {
            if (is_array($entry) && isset($entry['token'], $entry['device'], $entry['tuner'], $entry['expiresAt'])) {
                $entries[(string) $entry['token']] = [
                    'token'     => (string) $entry['token'],
                    'device'    => (string) $entry['device'],
                    'tuner'     => (int) $entry['tuner'],
                    'label'     => (string) ($entry['label'] ?? ''),
                    'createdAt' => (int) ($entry['createdAt'] ?? 0),
                    'expiresAt' => (int) $entry['expiresAt'],
                ];
            }
        }

        return $entries;
    }

    /**
     * @param array<string, array<string, mixed>> $entries
     * @return array<string, array<string, mixed>>
     */
    private static function current(array $entries): array
    {
        $now = time();

        return array_filter($entries, fn(array $entry) => $entry['expiresAt'] > $now);
    }
}
