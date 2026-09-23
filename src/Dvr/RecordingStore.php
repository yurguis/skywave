<?php

declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave\Dvr;

use PDO;
use RuntimeException;

/**
 * SQLite storage for scheduled and finished recordings, in the same database as the
 * program guide (WAL mode lets the web UI and the recorder use it at once).
 *
 * Schedules carry their own copy of the channel and program details rather than pointing
 * at the guide's tables: a channel rescan deletes and recreates those rows, and events
 * are pruned once they have aired, but a recording must outlive both. Times are Unix
 * timestamps (UTC).
 */
class RecordingStore
{
    /**
     * "ts" keeps the broadcast as it was sent, "mp4" converts it for browsers while
     * recording, and "both" records the broadcast and converts a copy once it ends.
     */
    public const FORMATS = ['ts', 'mp4', 'both'];

    /** A schedule waiting for its start time, then the recorder's outcome. */
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_RECORDING = 'recording';
    public const STATUS_DONE      = 'done';
    public const STATUS_FAILED    = 'failed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_MISSED    = 'missed';

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

        $this->migrate();
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
     * Schedule one showing. Scheduling the same showing twice returns the existing id.
     *
     * @param array<string, mixed> $schedule device, physical, program, virtual, channelName,
     *                                       title, start, duration and optionally eventId,
     *                                       description, padStart, padEnd, format
     */
    public function addSchedule(array $schedule): int
    {
        $existing = $this->findSchedule((string) $schedule['device'], (int) $schedule['physical'], (int) $schedule['program'], (int) $schedule['start']);

        if ($existing !== null && $existing['status'] === self::STATUS_SCHEDULED) {
            return $existing['id'];
        }

        $now       = time();
        $statement = $this->db->prepare(
            'INSERT OR REPLACE INTO schedules (
                device, physical, program, virtual, channel_name, event_id, start, duration,
                title, description, pad_start, pad_end, format, status, error, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?)'
        );

        $statement->execute([
            $schedule['device'],
            $schedule['physical'],
            $schedule['program'],
            $schedule['virtual'],
            $schedule['channelName'],
            $schedule['eventId'] ?? null,
            $schedule['start'],
            $schedule['duration'],
            $schedule['title'],
            $schedule['description'] ?? null,
            $schedule['padStart'] ?? 0,
            $schedule['padEnd'] ?? 0,
            in_array($schedule['format'] ?? 'ts', self::FORMATS, true) ? $schedule['format'] ?? 'ts' : 'ts',
            self::STATUS_SCHEDULED,
            $now,
            $now,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findSchedule(string $device, int $physical, int $program, int $start): ?array
    {
        $statement = $this->db->prepare(
            'SELECT * FROM schedules WHERE device = ? AND physical = ? AND program = ? AND start = CAST(? AS INTEGER)'
        );
        $statement->execute([$device, $physical, $program, $start]);
        $row = $statement->fetch();

        return $row === false ? null : self::castSchedule($row);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getSchedule(int $id): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM schedules WHERE id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch();

        return $row === false ? null : self::castSchedule($row);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getSchedules(?string $device = null, ?string $status = null): array
    {
        $where      = [];
        $parameters = [];

        if ($device !== null) {
            $where[]      = 'device = ?';
            $parameters[] = $device;
        }

        if ($status !== null) {
            $where[]      = 'status = ?';
            $parameters[] = $status;
        }

        $statement = $this->db->prepare(
            'SELECT * FROM schedules' . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)) . ' ORDER BY start'
        );
        $statement->execute($parameters);

        return array_map([self::class, 'castSchedule'], $statement->fetchAll());
    }

    /**
     * Schedules whose recording window covers now, padding included.
     *
     * @return list<array<string, mixed>>
     */
    public function getDueSchedules(int $now): array
    {
        $statement = $this->db->prepare(
            'SELECT * FROM schedules
              WHERE status = ?
                AND start - pad_start <= CAST(? AS INTEGER)
                AND start + duration + pad_end > CAST(? AS INTEGER)
                AND (retry_after IS NULL OR retry_after <= CAST(? AS INTEGER))
              ORDER BY start'
        );
        $statement->execute([self::STATUS_SCHEDULED, $now, $now, $now]);

        return array_map([self::class, 'castSchedule'], $statement->fetchAll());
    }

    /**
     * Schedules whose window has passed without being recorded, e.g. while the recorder
     * was not running.
     *
     * @return list<array<string, mixed>>
     */
    public function getMissedSchedules(int $now): array
    {
        $statement = $this->db->prepare(
            'SELECT * FROM schedules WHERE status = ? AND start + duration + pad_end <= CAST(? AS INTEGER) ORDER BY start'
        );
        $statement->execute([self::STATUS_SCHEDULED, $now]);

        return array_map([self::class, 'castSchedule'], $statement->fetchAll());
    }

    /**
     * Put a schedule back in the queue for another attempt, not before $notBefore.
     *
     * The attempt count lives here rather than being counted from recordings: a program
     * that could not be started leaves no recording to count.
     */
    public function retrySchedule(int $id, int $attempts, int $notBefore): void
    {
        $statement = $this->db->prepare(
            'UPDATE schedules SET status = ?, attempts = ?, retry_after = ?, error = NULL, updated_at = ? WHERE id = ?'
        );
        $statement->execute([self::STATUS_SCHEDULED, $attempts, $notBefore, time(), $id]);
    }

    public function markSchedule(int $id, string $status, ?string $error = null): void
    {
        $statement = $this->db->prepare('UPDATE schedules SET status = ?, error = ?, updated_at = ? WHERE id = ?');
        $statement->execute([$status, $error, time(), $id]);
    }

    public function deleteSchedule(int $id): bool
    {
        $statement = $this->db->prepare('DELETE FROM schedules WHERE id = ?');
        $statement->execute([$id]);

        return $statement->rowCount() > 0;
    }

    /**
     * Record that a recording has started.
     *
     * @param array<string, mixed> $recording scheduleId, device, physical, program, virtual,
     *                                        channelName, title, description, path, format,
     *                                        tuner, pid, startedAt, stopsAt, reservation
     */
    public function addRecording(array $recording): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO recordings (
                schedule_id, device, physical, program, virtual, channel_name, title, description,
                path, format, tuner, pid, started_at, stops_at, ended_at, bytes, status, error,
                reservation, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, 0, ?, NULL, ?, ?)'
        );

        $statement->execute([
            $recording['scheduleId'] ?? null,
            $recording['device'],
            $recording['physical'],
            $recording['program'],
            $recording['virtual'],
            $recording['channelName'],
            $recording['title'],
            $recording['description'] ?? null,
            $recording['path'],
            $recording['format'],
            $recording['tuner'],
            $recording['pid'],
            $recording['startedAt'],
            $recording['stopsAt'],
            self::STATUS_RECORDING,
            $recording['reservation'] ?? null,
            time(),
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * @param array<string, mixed> $fields any of bytes, pid, status, endedAt, error, path,
     *                                     stopsAt, reservation
     */
    public function updateRecording(int $id, array $fields): void
    {
        $columns = [
            'bytes'         => 'bytes',
            'pid'           => 'pid',
            'status'        => 'status',
            'endedAt'       => 'ended_at',
            'error'         => 'error',
            'path'          => 'path',
            'stopsAt'       => 'stops_at',
            'reservation'   => 'reservation',
            'stopRequested' => 'stop_requested',
            // Written once, when the recording ends and the picture is copied for it.
            'artworkPath'    => 'artwork_path',
            'description'    => 'description',
            'convertedPath'  => 'converted_path',
            'convertedBytes' => 'converted_bytes',
            'convertPid'     => 'convert_pid',
            'convertError'   => 'convert_error',
            // Set by hand from the page, to convert a recording that was kept as broadcast.
            'convertRequested' => 'convert_requested',
            // Null keeps whatever was broadcast; a number scales to that many lines.
            'convertHeight' => 'convert_height',
        ];

        $assignments = [];
        $parameters  = [];

        foreach ($fields as $name => $value) {
            if (isset($columns[$name])) {
                $assignments[] = "{$columns[$name]} = ?";
                $parameters[]  = $value;
            }
        }

        if ($assignments === []) {
            return;
        }

        $assignments[] = 'updated_at = ?';
        $parameters[]  = time();
        $parameters[]  = $id;

        $statement = $this->db->prepare('UPDATE recordings SET ' . implode(', ', $assignments) . ' WHERE id = ?');
        $statement->execute($parameters);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getRecording(int $id): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM recordings WHERE id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch();

        return $row === false ? null : self::castRecording($row);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getRecordings(?string $device = null, ?string $status = null): array
    {
        $where      = [];
        $parameters = [];

        if ($device !== null) {
            $where[]      = 'device = ?';
            $parameters[] = $device;
        }

        if ($status !== null) {
            $where[]      = 'status = ?';
            $parameters[] = $status;
        }

        $statement = $this->db->prepare(
            'SELECT * FROM recordings' . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)) . ' ORDER BY started_at DESC'
        );
        $statement->execute($parameters);

        return array_map([self::class, 'castRecording'], $statement->fetchAll());
    }

    /**
     * Finished "both" recordings that still need their browser-ready copy, including any
     * whose conversion died with the recorder that started it.
     *
     * @return list<array<string, mixed>>
     */
    public function getRecordingsToConvert(): array
    {
        $statement = $this->db->prepare(
            'SELECT * FROM recordings
              WHERE (format = ? OR convert_requested = 1)
                AND status = ?
                AND converted_path IS NULL
                AND convert_error IS NULL
                AND convert_pid IS NULL
              ORDER BY ended_at'
        );
        $statement->execute(['both', self::STATUS_DONE]);

        return array_map([self::class, 'castRecording'], $statement->fetchAll());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getConvertingRecordings(): array
    {
        $statement = $this->db->query(
            'SELECT * FROM recordings WHERE convert_pid IS NOT NULL AND converted_path IS NULL ORDER BY ended_at'
        );

        return $statement === false ? [] : array_map([self::class, 'castRecording'], $statement->fetchAll());
    }

    /**
     * Ask the recorder to stop a recording and finish it with $status.
     *
     * Only the recorder can signal its own ffmpeg: it runs in another container, whose
     * process ids mean nothing here. The request is picked up on the next tick.
     */
    public function requestStop(int $id, string $status): bool
    {
        $recording = $this->getRecording($id);

        if ($recording === null || $recording['status'] !== self::STATUS_RECORDING) {
            return false;
        }

        $this->updateRecording($id, ['stopRequested' => $status, 'stopsAt' => time()]);

        return true;
    }

    public function deleteRecording(int $id): bool
    {
        $statement = $this->db->prepare('DELETE FROM recordings WHERE id = ?');
        $statement->execute([$id]);

        return $statement->rowCount() > 0;
    }

    /**
     * Record every showing of a title on one channel. Asking twice returns the same rule.
     *
     * @param array<string, mixed> $rule device, physical, program, virtual, channelName,
     *                                   title and optionally earliest, latest, days,
     *                                   timezone, format, padStart, padEnd
     */
    public function addRule(array $rule): int
    {
        $existing = $this->findRule((string) $rule['device'], (int) $rule['physical'], (int) $rule['program'], (string) $rule['title']);

        if ($existing !== null) {
            return $existing['id'];
        }

        $now       = time();
        $statement = $this->db->prepare(
            'INSERT INTO rules (
                device, physical, program, virtual, channel_name, title, earliest, latest,
                days, timezone, format, pad_start, pad_end, active, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)'
        );

        $statement->execute([
            $rule['device'],
            $rule['physical'],
            $rule['program'],
            $rule['virtual'],
            $rule['channelName'],
            $rule['title'],
            $rule['earliest'] ?? null,
            $rule['latest'] ?? null,
            $rule['days'] ?? null,
            $rule['timezone'] ?? 'UTC',
            in_array($rule['format'] ?? 'ts', self::FORMATS, true) ? $rule['format'] ?? 'ts' : 'ts',
            $rule['padStart'] ?? 0,
            $rule['padEnd'] ?? 0,
            $now,
            $now,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findRule(string $device, int $physical, int $program, string $title): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM rules WHERE device = ? AND physical = ? AND program = ? AND title = ?');
        $statement->execute([$device, $physical, $program, $title]);
        $row = $statement->fetch();

        return $row === false ? null : self::castRule($row);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getRules(?string $device = null, bool $activeOnly = false): array
    {
        $where      = [];
        $parameters = [];

        if ($device !== null) {
            $where[]      = 'device = ?';
            $parameters[] = $device;
        }

        if ($activeOnly) {
            $where[] = 'active = 1';
        }

        $sql       = 'SELECT * FROM rules' . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)) . ' ORDER BY title';
        $statement = $this->db->prepare($sql);
        $statement->execute($parameters);

        return array_map([self::class, 'castRule'], $statement->fetchAll());
    }

    public function deleteRule(int $id): bool
    {
        $statement = $this->db->prepare('DELETE FROM rules WHERE id = ?');
        $statement->execute([$id]);

        return $statement->rowCount() > 0;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function castRule(array $row): array
    {
        return [
            'id'          => (int) $row['id'],
            'device'      => $row['device'],
            'physical'    => (int) $row['physical'],
            'program'     => (int) $row['program'],
            'virtual'     => $row['virtual'],
            'channelName' => $row['channel_name'],
            'title'       => $row['title'],
            'earliest'    => $row['earliest'] === null ? null : (int) $row['earliest'],
            'latest'      => $row['latest'] === null ? null : (int) $row['latest'],
            'days'        => $row['days'],
            'timezone'    => $row['timezone'],
            'format'      => $row['format'],
            'padStart'    => (int) $row['pad_start'],
            'padEnd'      => (int) $row['pad_end'],
            'active'      => (int) $row['active'] === 1,
            'createdAt'   => (int) $row['created_at'],
            'updatedAt'   => (int) $row['updated_at'],
        ];
    }

    private function migrate(): void
    {
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS schedules (
                id INTEGER PRIMARY KEY,
                device TEXT NOT NULL,
                physical INTEGER NOT NULL,
                program INTEGER NOT NULL,
                virtual TEXT NOT NULL,
                channel_name TEXT NOT NULL,
                event_id INTEGER,
                start INTEGER NOT NULL,
                duration INTEGER NOT NULL,
                title TEXT NOT NULL,
                description TEXT,
                pad_start INTEGER NOT NULL DEFAULT 0,
                pad_end INTEGER NOT NULL DEFAULT 0,
                format TEXT NOT NULL DEFAULT "ts",
                status TEXT NOT NULL DEFAULT "scheduled",
                error TEXT,
                attempts INTEGER NOT NULL DEFAULT 0,
                retry_after INTEGER,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                UNIQUE (device, physical, program, start)
            );
            CREATE INDEX IF NOT EXISTS schedules_by_start ON schedules (start);
            CREATE TABLE IF NOT EXISTS recordings (
                id INTEGER PRIMARY KEY,
                schedule_id INTEGER,
                device TEXT NOT NULL,
                physical INTEGER NOT NULL,
                program INTEGER NOT NULL,
                virtual TEXT NOT NULL,
                channel_name TEXT NOT NULL,
                title TEXT NOT NULL,
                description TEXT,
                path TEXT NOT NULL,
                format TEXT NOT NULL,
                tuner INTEGER,
                pid INTEGER,
                started_at INTEGER NOT NULL,
                stops_at INTEGER NOT NULL,
                ended_at INTEGER,
                bytes INTEGER NOT NULL DEFAULT 0,
                status TEXT NOT NULL,
                error TEXT,
                reservation TEXT,
                stop_requested TEXT,
                converted_path TEXT,
                converted_bytes INTEGER,
                convert_pid INTEGER,
                convert_error TEXT,
                updated_at INTEGER NOT NULL
            );
            CREATE INDEX IF NOT EXISTS recordings_by_status ON recordings (status);
            CREATE TABLE IF NOT EXISTS rules (
                id INTEGER PRIMARY KEY,
                device TEXT NOT NULL,
                physical INTEGER NOT NULL,
                program INTEGER NOT NULL,
                virtual TEXT NOT NULL,
                channel_name TEXT NOT NULL,
                title TEXT NOT NULL,
                earliest INTEGER,
                latest INTEGER,
                days TEXT,
                timezone TEXT NOT NULL DEFAULT "UTC",
                format TEXT NOT NULL DEFAULT "ts",
                pad_start INTEGER NOT NULL DEFAULT 0,
                pad_end INTEGER NOT NULL DEFAULT 0,
                active INTEGER NOT NULL DEFAULT 1,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                UNIQUE (device, physical, program, title)
            );'
        );

        // "IF NOT EXISTS" leaves an existing table as it was, so columns added after the
        // first release have to be added by hand.
        $this->addMissingColumns('schedules', [
            'attempts'    => 'INTEGER NOT NULL DEFAULT 0',
            'retry_after' => 'INTEGER',
        ]);
        $this->addMissingColumns('recordings', [
            // The picture this recording was made with, kept so it cannot change later.
            'artwork_path'      => 'TEXT',
            'convert_requested' => 'INTEGER NOT NULL DEFAULT 0',
            'convert_height'    => 'INTEGER',
            'reservation'       => 'TEXT',
            'stop_requested'    => 'TEXT',
            'converted_path'    => 'TEXT',
            'converted_bytes'   => 'INTEGER',
            'convert_pid'       => 'INTEGER',
            'convert_error'     => 'TEXT',
        ]);
    }

    /**
     * @param array<string, string> $columns column name to its SQLite type
     */
    private function addMissingColumns(string $table, array $columns): void
    {
        $statement = $this->db->query("PRAGMA table_info($table)");
        $existing  = $statement === false ? [] : array_column($statement->fetchAll(), 'name');

        foreach ($columns as $name => $type) {
            if (!in_array($name, $existing, true)) {
                $this->db->exec("ALTER TABLE $table ADD COLUMN $name $type");
            }
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function castSchedule(array $row): array
    {
        return [
            'id'          => (int) $row['id'],
            'device'      => $row['device'],
            'physical'    => (int) $row['physical'],
            'program'     => (int) $row['program'],
            'virtual'     => $row['virtual'],
            'channelName' => $row['channel_name'],
            'eventId'     => $row['event_id'] === null ? null : (int) $row['event_id'],
            'start'       => (int) $row['start'],
            'duration'    => (int) $row['duration'],
            'title'       => $row['title'],
            'description' => $row['description'],
            'padStart'    => (int) $row['pad_start'],
            'padEnd'      => (int) $row['pad_end'],
            'format'      => $row['format'],
            'status'      => $row['status'],
            'error'       => $row['error'],
            'attempts'    => (int) ($row['attempts'] ?? 0),
            'retryAfter'  => ($row['retry_after'] ?? null) === null ? null : (int) $row['retry_after'],
            'createdAt'   => (int) $row['created_at'],
            'updatedAt'   => (int) $row['updated_at'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function castRecording(array $row): array
    {
        return [
            'id'               => (int) $row['id'],
            'scheduleId'       => $row['schedule_id'] === null ? null : (int) $row['schedule_id'],
            'device'           => $row['device'],
            'physical'         => (int) $row['physical'],
            'program'          => (int) $row['program'],
            'virtual'          => $row['virtual'],
            'channelName'      => $row['channel_name'],
            'title'            => $row['title'],
            'description'      => $row['description'],
            'path'             => $row['path'],
            'format'           => $row['format'],
            'tuner'            => $row['tuner'] === null ? null : (int) $row['tuner'],
            'pid'              => $row['pid'] === null ? null : (int) $row['pid'],
            'startedAt'        => (int) $row['started_at'],
            'stopsAt'          => (int) $row['stops_at'],
            'endedAt'          => $row['ended_at'] === null ? null : (int) $row['ended_at'],
            'bytes'            => (int) $row['bytes'],
            'status'           => $row['status'],
            'error'            => $row['error'],
            'reservation'      => $row['reservation'],
            'stopRequested'    => $row['stop_requested'],
            'artworkPath'      => $row['artwork_path'] ?? null,
            'convertedPath'    => $row['converted_path'] ?? null,
            'convertedBytes'   => ($row['converted_bytes'] ?? null) === null ? null : (int) $row['converted_bytes'],
            'convertPid'       => ($row['convert_pid'] ?? null) === null ? null : (int) $row['convert_pid'],
            'convertError'     => $row['convert_error'] ?? null,
            'convertRequested' => (int) ($row['convert_requested'] ?? 0) === 1,
            'convertHeight'    => ($row['convert_height'] ?? null) === null ? null : (int) $row['convert_height'],
            'updatedAt'        => (int) $row['updated_at'],
        ];
    }
}
