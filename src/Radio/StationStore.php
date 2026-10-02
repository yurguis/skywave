<?php

declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave\Radio;

use PDO;
use RuntimeException;

/**
 * The radio stations that have been heard, kept so they can be picked rather than typed.
 *
 * Nothing on the FM band announces what else is on it, so the only list there can be is the
 * one made by listening: a station goes in when somebody plays it or a scan finds it, and
 * stays until it is removed by hand. It lives in the guide's database so that every browser
 * sees the same stations, which a list kept in one browser never managed.
 *
 * Stations are keyed by frequency, held in kHz so that 90.5 is a whole number and two
 * spellings of it cannot become two stations.
 */
class StationStore
{
    /** A station heard again this recently is not written again just to say so. */
    private const FRESH_SECONDS = 3600;

    private PDO $db;

    public function __construct(string $path)
    {
        $directory = dirname($path);

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException("Unable to create $directory");
        }

        $this->db = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->db->exec('PRAGMA journal_mode = WAL');
        $this->db->exec('PRAGMA busy_timeout = 5000');

        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS radio_stations (
                frequency INTEGER PRIMARY KEY,
                name TEXT,
                programs TEXT NOT NULL DEFAULT \'[]\',
                signal REAL,
                heard_at INTEGER NOT NULL
            )'
        );
    }

    /**
     * The same database as the guide (GUIDE_DB), by default data/guide.sqlite.
     */
    public static function fromEnvironment(): self
    {
        $path = getenv('GUIDE_DB');

        return new self($path === false || $path === '' ? dirname(__DIR__, 2) . '/data/guide.sqlite' : $path);
    }

    /**
     * Note a station as heard.
     *
     * What is already known is kept where the new report says nothing: a station is found
     * before it has given its name, and one caught in a weak moment may list fewer programs
     * than it carries. This is asked every couple of seconds for as long as somebody
     * listens, so it only writes when there is something to write.
     *
     * @param list<array{number: int, name: ?string, type: ?string}> $programs
     * @return bool whether anything was written
     */
    public function save(float $frequency, ?string $name, array $programs = [], ?float $signal = null): bool
    {
        $key   = self::key($frequency);
        $known = $this->find($frequency);
        $name  = $name === null || trim($name) === '' ? null : trim($name);

        if ($known !== null) {
            $name     = $name ?? $known['name'];
            $programs = count($programs) >= count($known['programs']) ? $programs : $known['programs'];

            $unchanged = $name === $known['name'] && $programs == $known['programs'];

            if ($unchanged && time() - $known['heardAt'] < self::FRESH_SECONDS) {
                return false;
            }
        }

        $this->db->prepare(
            'INSERT INTO radio_stations (frequency, name, programs, signal, heard_at)
             VALUES (:frequency, :name, :programs, :signal, :now)
             ON CONFLICT (frequency) DO UPDATE SET
                name = excluded.name, programs = excluded.programs,
                signal = COALESCE(excluded.signal, signal), heard_at = excluded.heard_at'
        )->execute([
            'frequency' => $key,
            'name'      => $name,
            'programs'  => json_encode(array_values($programs), JSON_INVALID_UTF8_SUBSTITUTE),
            'signal'    => $signal,
            'now'       => time(),
        ]);

        return true;
    }

    /**
     * @return array{frequency: float, name: ?string, programs: list<array<string, mixed>>, signal: ?float, heardAt: int}|null
     */
    public function find(float $frequency): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM radio_stations WHERE frequency = ?');
        $statement->execute([self::key($frequency)]);
        $row = $statement->fetch();

        return $row === false ? null : self::cast($row);
    }

    /**
     * @return list<array{frequency: float, name: ?string, programs: list<array<string, mixed>>, signal: ?float, heardAt: int}> up the dial
     */
    public function all(): array
    {
        $statement = $this->db->query('SELECT * FROM radio_stations ORDER BY frequency');

        return $statement === false ? [] : array_map([self::class, 'cast'], $statement->fetchAll());
    }

    public function remove(float $frequency): bool
    {
        $statement = $this->db->prepare('DELETE FROM radio_stations WHERE frequency = ?');
        $statement->execute([self::key($frequency)]);

        return $statement->rowCount() > 0;
    }

    private static function key(float $frequency): int
    {
        return (int) round($frequency * 1000);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{frequency: float, name: ?string, programs: list<array<string, mixed>>, signal: ?float, heardAt: int}
     */
    private static function cast(array $row): array
    {
        $programs = json_decode((string) $row['programs'], true);

        return [
            'frequency' => round((int) $row['frequency'] / 1000, 1),
            'name'      => $row['name'],
            'programs'  => is_array($programs) ? array_values($programs) : [],
            'signal'    => $row['signal'] === null ? null : (float) $row['signal'],
            'heardAt'   => (int) $row['heard_at'],
        ];
    }
}
