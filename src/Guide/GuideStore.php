<?php

declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave\Guide;

use PDO;
use RuntimeException;

/**
 * SQLite storage for channel lineups and program guide events, shared by the web UI
 * and the background collector (WAL mode lets both use it at once).
 *
 * Channels are keyed by device address, physical channel and MPEG program number; events
 * by channel, ATSC event id and start time. Times are Unix timestamps (UTC).
 */
class GuideStore
{
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
        $this->db->exec('PRAGMA foreign_keys = ON');

        $this->migrate();
    }

    /**
     * Database at GUIDE_DB, by default data/guide.sqlite in the project.
     */
    public static function fromEnvironment(): self
    {
        $path = getenv('GUIDE_DB');

        return new self($path === false || $path === '' ? dirname(__DIR__, 2) . '/data/guide.sqlite' : $path);
    }

    /**
     * Replace a device's lineup with a channel scan result. Channels no longer found
     * are removed together with their events.
     *
     * @param list<array{physical: int, program: int, virtual: string, name: string, tsid: ?int, encrypted: bool, hd?: bool}> $channels
     */
    public function saveLineup(string $device, array $channels): void
    {
        $now = time();

        $this->transaction(function () use ($device, $channels, $now): void {
            $upsert = $this->db->prepare(
                'INSERT INTO channels (device, physical, program, virtual, name, tsid, encrypted, hd, updated_at)
                 VALUES (:device, :physical, :program, :virtual, :name, :tsid, :encrypted, :hd, :now)
                 ON CONFLICT (device, physical, program) DO UPDATE SET
                    virtual = excluded.virtual, name = excluded.name, tsid = excluded.tsid,
                    encrypted = excluded.encrypted, hd = excluded.hd, updated_at = excluded.updated_at'
            );

            $keep = [];

            foreach ($channels as $channel) {
                $upsert->execute([
                    'device'    => $device,
                    'physical'  => $channel['physical'],
                    'program'   => $channel['program'],
                    'virtual'   => $channel['virtual'],
                    'name'      => $channel['name'],
                    'tsid'      => $channel['tsid'],
                    'encrypted' => $channel['encrypted'] ? 1 : 0,
                    'hd'        => ($channel['hd'] ?? false) ? 1 : 0,
                    'now'       => $now,
                ]);
                $keep[] = $channel['physical'] . ':' . $channel['program'];
            }

            $existing = $this->db->prepare('SELECT id, physical, program FROM channels WHERE device = ?');
            $existing->execute([$device]);
            $delete = $this->db->prepare('DELETE FROM channels WHERE id = ?');

            foreach ($existing->fetchAll() as $row) {
                if (!in_array($row['physical'] . ':' . $row['program'], $keep, true)) {
                    $delete->execute([$row['id']]);
                }
            }
        });
    }

    /**
     * Channels of a device ordered by virtual channel number.
     *
     * @return list<array<string, mixed>>
     */
    public function getLineup(?string $device = null): array
    {
        $statement = $device === null
            ? $this->db->query('SELECT * FROM channels')
            : $this->db->prepare('SELECT * FROM channels WHERE device = ?');

        if ($device !== null) {
            $statement->execute([$device]);
        }

        $channels = array_map([self::class, 'castChannel'], $statement->fetchAll());
        usort($channels, fn (array $a, array $b) => [$a['device'], self::virtualKey($a['virtual'])] <=> [$b['device'], self::virtualKey($b['virtual'])]);

        return $channels;
    }

    /** Stations the device's own lineup reports. */
    public const ATSC3_SOURCE_LINEUP = 'lineup';

    /** Stations read from the broadcast's Service List Table, which the lineup omits. */
    public const ATSC3_SOURCE_SLT = 'slt';

    /**
     * The 1.0 channel an ATSC 3.0 service simulcasts, by the numbering the device uses: a
     * hundred on the major channel. Null when the number cannot be one.
     *
     * Kept here so the guide and the pictures agree on what counts as a counterpart.
     */
    public static function atsc3Counterpart(string $virtual): ?string
    {
        if (!preg_match('/^(\d{1,4})\.(\d{1,4})$/', $virtual, $match) || (int) $match[1] <= 100) {
            return null;
        }

        return ((int) $match[1] - 100) . '.' . $match[2];
    }

    /**
     * Replace the ATSC 3.0 stations known for a device, for one source only.
     *
     * Deliberately not in `channels`: that table drives scanning, tuning and recording, and
     * nothing here can tune ATSC 3.0. Putting them there would have the collector trying to
     * tune physical channels that carry no transport stream.
     *
     * @param list<array<string, mixed>> $channels
     */
    public function saveAtsc3Lineup(string $device, array $channels, string $source = self::ATSC3_SOURCE_LINEUP): void
    {
        $now = time();

        $this->transaction(function () use ($device, $channels, $now, $source): void {
            // Each source is the whole truth for what it reports, and nothing more: clearing
            // only its own rows lets a second source describe stations the first never
            // mentions without the two erasing each other every few hours.
            $this->db->prepare('DELETE FROM atsc3_channels WHERE device = ? AND source = ?')
                ->execute([$device, $source]);

            $insert = $this->db->prepare(
                'INSERT OR REPLACE INTO atsc3_channels
                    (device, virtual, name, video_codec, audio_codec, drm, broadband, hd, source, stream_url, updated_at)
                 VALUES (:device, :virtual, :name, :video, :audio, :drm, :broadband, :hd, :source, :stream, :now)'
            );

            foreach ($channels as $channel) {
                $insert->execute([
                    'device'    => $device,
                    'virtual'   => (string) $channel['virtual'],
                    'name'      => (string) $channel['name'],
                    'video'     => $channel['videoCodec'] ?? null,
                    'audio'     => $channel['audioCodec'] ?? null,
                    'drm'       => empty($channel['drm']) ? 0 : 1,
                    'broadband' => empty($channel['broadband']) ? 0 : 1,
                    'hd'        => empty($channel['hd']) ? 0 : 1,
                    'source'    => $source,
                    'stream'    => $channel['streamUrl'] ?? null,
                    'now'       => $now,
                ]);
            }
        });
    }

    /**
     * ATSC 3.0 stations, shaped like guide channels so the page draws them in the same
     * list. Events are filled in by the guide: a 3.0 multiplex carries no PSIP tables, so
     * they come from a service guide the broadcast announces or from the channel it
     * simulcasts, never from this table.
     *
     * @return list<array<string, mixed>>
     */
    public function getAtsc3Lineup(?string $device = null): array
    {
        $statement = $device === null
            ? $this->db->query('SELECT * FROM atsc3_channels')
            : $this->db->prepare('SELECT * FROM atsc3_channels WHERE device = ?');

        if ($device !== null) {
            $statement->execute([$device]);
        }

        return array_map(static fn (array $row): array => [
            // A string id keeps these clear of the integer ids real channels carry.
            'id'       => 'atsc3:' . $row['device'] . ':' . $row['virtual'],
            'device'   => $row['device'],
            'physical' => null,
            'program'  => null,
            'virtual'  => (string) $row['virtual'],
            'name'     => (string) $row['name'],
            'tsid'     => null,
            'audio'    => null,
            'hd'       => (bool) $row['hd'],
            'drm'      => (bool) $row['drm'],
            // Delivered over the internet rather than purely over the air. Separate
            // from protection: a station can be one, the other, both or neither.
            'broadband' => (bool) ($row['broadband'] ?? false),
            'atsc3'     => true,
            // What disables Watch in the page, which is what an encrypted station deserves.
            'encrypted'  => (bool) $row['drm'],
            'videoCodec' => $row['video_codec'],
            'audioCodec' => $row['audio_codec'],
            'source'     => (string) ($row['source'] ?? self::ATSC3_SOURCE_LINEUP),
            // Where its media is served, when the broadcast says so. Only some ATSC 3.0
            // services carry their media over the internet; the rest have none.
            'streamUrl' => $row['stream_url'] ?? null,
            'events'    => [],
        ], $statement === false ? [] : $statement->fetchAll());
    }

    /**
     * Store the programme listings an ATSC 3.0 service announces about itself.
     *
     * Kept apart from `events`, which hangs off `channels` by foreign key and so can only
     * describe a channel a tuner can reach. These stations are never in that table, and
     * are keyed by their virtual number instead.
     *
     * Rows are replaced one at a time rather than cleared per station: the announcement
     * arrives in pieces, and a reading that recovers less than the one before it should
     * refresh what it saw without erasing what it missed.
     *
     * @param list<array<string, mixed>> $events virtual, eventId, start, duration, title, rating, description
     * @return int events stored
     */
    public function saveAtsc3Events(string $device, array $events): int
    {
        $now   = time();
        $saved = 0;

        $this->transaction(function () use ($device, $events, $now, &$saved): void {
            $insert = $this->db->prepare(
                'INSERT OR REPLACE INTO atsc3_events
                    (device, virtual, event_id, start, duration, title, rating, description, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );

            foreach ($events as $event) {
                $insert->execute([
                    $device,
                    (string) $event['virtual'],
                    (int) $event['eventId'],
                    (int) $event['start'],
                    (int) $event['duration'],
                    (string) $event['title'],
                    $event['rating'] ?? null,
                    $event['description'] ?? null,
                    $now,
                ]);
                $saved++;
            }
        });

        return $saved;
    }

    /**
     * Store the guide read from one physical channel's tables.
     *
     * Events replace whatever the channel had in the time span they cover, so schedule
     * changes and cancellations propagate.
     *
     * @param list<array<string, mixed>> $vctChannels JsonRenderer "channels" entries
     * @return array{channels: int, events: int}
     */
    public function saveChannelGuide(string $device, int $physical, ?int $tsid, array $vctChannels): array
    {
        $now    = time();
        $counts = ['channels' => 0, 'events' => 0];

        $this->transaction(function () use ($device, $physical, $tsid, $vctChannels, $now, &$counts): void {
            $upsert = $this->db->prepare(
                'INSERT INTO channels (device, physical, program, virtual, name, tsid, source_id, audio, updated_at)
                 VALUES (:device, :physical, :program, :virtual, :name, :tsid, :source, :audio, :now)
                 ON CONFLICT (device, physical, program) DO UPDATE SET
                    virtual = excluded.virtual, name = excluded.name, tsid = excluded.tsid,
                    source_id = excluded.source_id, audio = excluded.audio, updated_at = excluded.updated_at'
            );
            $find        = $this->db->prepare('SELECT id FROM channels WHERE device = ? AND physical = ? AND program = ?');
            $clearWindow = $this->db->prepare('DELETE FROM events WHERE channel_id = ? AND start >= ? AND start < ?');
            $insertEvent = $this->db->prepare(
                'INSERT OR REPLACE INTO events (channel_id, event_id, start, duration, title, rating, description, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );

            foreach ($vctChannels as $vct) {
                if (!empty($vct['hidden']) || (int) $vct['programNumber'] === 0) {
                    continue;
                }

                // The programme's audio tracks, in the order the multiplex carries them, so
                // live playback can offer a second language without asking the tuner again.
                $audio = self::audioTracks($vct['streams'] ?? []);

                $upsert->execute([
                    'device'   => $device,
                    'physical' => $physical,
                    'program'  => (int) $vct['programNumber'],
                    'virtual'  => (string) $vct['channel'],
                    'name'     => (string) $vct['name'],
                    'tsid'     => $tsid,
                    'source'   => (int) $vct['sourceId'],
                    'audio'    => $audio === [] ? null : json_encode($audio),
                    'now'      => $now,
                ]);
                $find->execute([$device, $physical, (int) $vct['programNumber']]);
                $channelId = (int) $find->fetchColumn();
                $counts['channels']++;

                $events = array_map(fn (array $event) => ['start' => strtotime((string) $event['start'])] + $event, $vct['events'] ?? []);

                if ($events === []) {
                    continue;
                }

                $clearWindow->execute([
                    $channelId,
                    min(array_column($events, 'start')),
                    max(array_map(fn (array $event) => $event['start'] + (int) $event['durationSeconds'], $events)),
                ]);

                foreach ($events as $event) {
                    $insertEvent->execute([
                        $channelId,
                        (int) $event['eventId'],
                        $event['start'],
                        (int) $event['durationSeconds'],
                        (string) $event['title'],
                        $event['rating'],
                        $event['description'],
                        $now,
                    ]);
                    $counts['events']++;
                }
            }
        });

        return $counts;
    }

    /**
     * Channels with the events overlapping [$from, $to).
     *
     * @return list<array<string, mixed>>
     */
    public function getGuide(int $from, int $to, ?string $device = null): array
    {
        // PDO binds parameters as text, and SQLite ranks any text above any number when the
        // other side is an expression rather than a column, hence the casts.
        $events = $this->db->prepare(
            'SELECT event_id, start, duration, title, rating, description FROM events
             WHERE channel_id = ? AND start < CAST(? AS INTEGER) AND start + duration > CAST(? AS INTEGER) ORDER BY start'
        );

        $guide = [];

        foreach ($this->getLineup($device) as $channel) {
            $events->execute([$channel['id'], $to, $from]);
            $channel['events'] = array_map(fn (array $event) => [
                'eventId'     => (int) $event['event_id'],
                'start'       => (int) $event['start'],
                'duration'    => (int) $event['duration'],
                'title'       => $event['title'],
                'rating'      => $event['rating'],
                'description' => $event['description'],
            ], $events->fetchAll());
            $guide[] = $channel;
        }

        // A service that announces a guide about itself is described by it, which is the
        // only way an encrypted station says what it is showing. Only when it announces
        // none does the channel a hundred below stand in, which it can because the two
        // simulcast the same programmes. A service with neither stays empty.
        $byVirtual = [];

        foreach ($guide as $channel) {
            $byVirtual[$channel['virtual']] = $channel['events'];
        }

        $announced = $this->db->prepare(
            'SELECT event_id, start, duration, title, rating, description FROM atsc3_events
             WHERE device = ? AND virtual = ? AND start < CAST(? AS INTEGER) AND start + duration > CAST(? AS INTEGER)
             ORDER BY start'
        );

        foreach ($this->getAtsc3Lineup($device) as $channel) {
            $announced->execute([$channel['device'], $channel['virtual'], $to, $from]);
            $own = array_map(fn (array $event) => [
                'eventId'     => (int) $event['event_id'],
                'start'       => (int) $event['start'],
                'duration'    => (int) $event['duration'],
                'title'       => $event['title'],
                'rating'      => $event['rating'],
                'description' => $event['description'],
            ], $announced->fetchAll());

            if ($own === []) {
                $counterpart = self::atsc3Counterpart((string) $channel['virtual']);
                $own         = $counterpart === null ? [] : ($byVirtual[$counterpart] ?? []);
            }

            $channel['events'] = $own;
            $guide[]           = $channel;
        }

        usort($guide, fn (array $a, array $b) => [$a['device'], self::virtualKey($a['virtual'])] <=> [$b['device'], self::virtualKey($b['virtual'])]);

        return $guide;
    }

    /**
     * @return array{first: ?int, last: ?int} earliest start and latest end of stored events
     */
    public function getDataRange(?string $device = null): array
    {
        $sql = 'SELECT MIN(e.start) AS first, MAX(e.start + e.duration) AS last FROM events e';

        if ($device !== null) {
            $statement = $this->db->prepare("$sql JOIN channels c ON c.id = e.channel_id WHERE c.device = ?");
            $statement->execute([$device]);
        } else {
            $statement = $this->db->query($sql);
        }

        $row = $statement->fetch() ?: [];

        return [
            'first' => isset($row['first']) ? (int) $row['first'] : null,
            'last'  => isset($row['last']) ? (int) $row['last'] : null,
        ];
    }

    /**
     * Delete events that ended before $before and were not refreshed since
     * $untouchedSince. Events a collection just stored stay even when old, which keeps
     * the guide of a replayed recording (see tools/fake-hdhomerun.php) usable.
     */
    public function pruneEvents(int $before, int $untouchedSince): int
    {
        $statement = $this->db->prepare('DELETE FROM events WHERE start + duration < CAST(? AS INTEGER) AND updated_at < CAST(? AS INTEGER)');
        $statement->execute([$before, $untouchedSince]);

        return $statement->rowCount();
    }

    /**
     * Mark which of a device's channels are high definition.
     *
     * Kept apart from saving a lineup because the flag comes from the device rather than
     * the broadcast, and costs one request: a guide update can refresh it without the
     * channel scan that a lineup needs.
     *
     * @param array<string, bool> $hdByVirtual virtual channel number to whether it is HD
     * @return int channels whose flag changed
     */
    public function setHdFlags(string $device, array $hdByVirtual): int
    {
        if ($hdByVirtual === []) {
            return 0;
        }

        $update  = $this->db->prepare('UPDATE channels SET hd = ? WHERE device = ? AND virtual = ? AND hd != ?');
        $changed = 0;

        foreach ($hdByVirtual as $virtual => $isHd) {
            $flag = $isHd ? 1 : 0;
            $update->execute([$flag, $device, (string) $virtual, $flag]);
            $changed += $update->rowCount();
        }

        return $changed;
    }

    /**
     * Remember a device somebody added by address, so every browser sees it and not just
     * the one it was typed into.
     */
    /**
     * Remember that a programme has no picture, so it is not looked up again every time the
     * guide refreshes. A channel showing "Paid Programming" all afternoon is the reason.
     */
    public function rememberArtworkMiss(string $key, string $title): void
    {
        $statement = $this->db->prepare(
            'INSERT INTO artwork_misses (key, title, checked_at) VALUES (?, ?, ?)
             ON CONFLICT (key) DO UPDATE SET title = excluded.title, checked_at = excluded.checked_at'
        );
        $statement->execute([$key, $title, time()]);
    }

    /**
     * Whether a programme was looked up and not found since $since. A miss is not forever:
     * something missing today may be added later.
     */
    public function artworkMissedRecently(string $key, int $since): bool
    {
        $statement = $this->db->prepare('SELECT checked_at FROM artwork_misses WHERE key = ?');
        $statement->execute([$key]);
        $checked = $statement->fetchColumn();

        return $checked !== false && (int) $checked >= $since;
    }

    /**
     * Every programme title in the guide, so artwork can be fetched for what is on.
     *
     * @return list<string>
     */
    public function eventTitles(?string $device = null): array
    {
        $statement = $device === null
            ? $this->db->query('SELECT DISTINCT title FROM events')
            : $this->db->prepare('SELECT DISTINCT e.title FROM events e JOIN channels c ON c.id = e.channel_id WHERE c.device = ?');

        if ($device !== null) {
            $statement->execute([$device]);
        }

        return array_values(array_filter(array_map('strval', $statement->fetchAll(\PDO::FETCH_COLUMN))));
    }

    public function addDevice(string $host): void
    {
        $statement = $this->db->prepare('INSERT OR IGNORE INTO devices (host, added_at) VALUES (?, ?)');
        $statement->execute([$host, time()]);
    }

    /**
     * @return string[] addresses, oldest first
     */
    public function getDevices(): array
    {
        $statement = $this->db->query('SELECT host FROM devices ORDER BY added_at');

        return $statement === false ? [] : array_column($statement->fetchAll(), 'host');
    }

    public function removeDevice(string $host): bool
    {
        $statement = $this->db->prepare('DELETE FROM devices WHERE host = ?');
        $statement->execute([$host]);

        return $statement->rowCount() > 0;
    }

    public function startRun(string $device, string $kind): int
    {
        $this->db->prepare('INSERT INTO runs (device, kind, started_at) VALUES (?, ?, ?)')->execute([$device, $kind, time()]);

        return (int) $this->db->lastInsertId();
    }

    public function finishRun(int $id, int $channels, int $events, ?string $error = null): void
    {
        $this->db->prepare('UPDATE runs SET finished_at = ?, channels = ?, events = ?, error = ? WHERE id = ?')
            ->execute([time(), $channels, $events, $error, $id]);
    }

    /**
     * @return list<array<string, mixed>> newest first
     */
    public function getRecentRuns(int $limit = 10, ?string $device = null, ?string $kind = null): array
    {
        $where  = [];
        $params = [];

        if ($device !== null) {
            $where[]  = 'device = ?';
            $params[] = $device;
        }

        if ($kind !== null) {
            $where[]  = 'kind = ?';
            $params[] = $kind;
        }

        $statement = $this->db->prepare(
            'SELECT * FROM runs' . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)) . ' ORDER BY id DESC LIMIT ' . max(1, $limit)
        );
        $statement->execute($params);

        return array_map(fn (array $run) => [
            'id'         => (int) $run['id'],
            'device'     => $run['device'],
            'kind'       => $run['kind'],
            'startedAt'  => (int) $run['started_at'],
            'finishedAt' => $run['finished_at'] === null ? null : (int) $run['finished_at'],
            'channels'   => $run['channels'] === null ? null : (int) $run['channels'],
            'events'     => $run['events'] === null ? null : (int) $run['events'],
            'error'      => $run['error'],
        ], $statement->fetchAll());
    }

    private function migrate(): void
    {
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS channels (
                id INTEGER PRIMARY KEY,
                device TEXT NOT NULL,
                physical INTEGER NOT NULL,
                program INTEGER NOT NULL,
                virtual TEXT NOT NULL,
                name TEXT NOT NULL,
                tsid INTEGER,
                source_id INTEGER,
                encrypted INTEGER NOT NULL DEFAULT 0,
                hd INTEGER NOT NULL DEFAULT 0,
                audio TEXT,
                updated_at INTEGER NOT NULL,
                UNIQUE (device, physical, program)
            );
            CREATE TABLE IF NOT EXISTS events (
                channel_id INTEGER NOT NULL REFERENCES channels (id) ON DELETE CASCADE,
                event_id INTEGER NOT NULL,
                start INTEGER NOT NULL,
                duration INTEGER NOT NULL,
                title TEXT NOT NULL,
                rating TEXT,
                description TEXT,
                updated_at INTEGER NOT NULL,
                PRIMARY KEY (channel_id, event_id, start)
            );
            CREATE INDEX IF NOT EXISTS events_by_time ON events (start);
            CREATE TABLE IF NOT EXISTS runs (
                id INTEGER PRIMARY KEY,
                device TEXT NOT NULL,
                kind TEXT NOT NULL,
                started_at INTEGER NOT NULL,
                finished_at INTEGER,
                channels INTEGER,
                events INTEGER,
                error TEXT
            );
            CREATE TABLE IF NOT EXISTS artwork_misses (
                key TEXT PRIMARY KEY,
                title TEXT NOT NULL,
                checked_at INTEGER NOT NULL
            );
            CREATE TABLE IF NOT EXISTS atsc3_channels (
                device TEXT NOT NULL,
                virtual TEXT NOT NULL,
                name TEXT NOT NULL,
                video_codec TEXT,
                audio_codec TEXT,
                drm INTEGER NOT NULL DEFAULT 0,
                broadband INTEGER NOT NULL DEFAULT 0,
                stream_url TEXT,
                hd INTEGER NOT NULL DEFAULT 0,
                source TEXT NOT NULL DEFAULT \'lineup\',
                updated_at INTEGER NOT NULL,
                UNIQUE (device, virtual)
            );
            CREATE TABLE IF NOT EXISTS atsc3_events (
                device TEXT NOT NULL,
                virtual TEXT NOT NULL,
                event_id INTEGER NOT NULL,
                start INTEGER NOT NULL,
                duration INTEGER NOT NULL,
                title TEXT NOT NULL,
                rating TEXT,
                description TEXT,
                updated_at INTEGER NOT NULL,
                PRIMARY KEY (device, virtual, start)
            );
            CREATE INDEX IF NOT EXISTS atsc3_events_by_time ON atsc3_events (start);
            CREATE TABLE IF NOT EXISTS devices (
                host TEXT PRIMARY KEY,
                added_at INTEGER NOT NULL
            );'
        );

        // "IF NOT EXISTS" leaves a table that already exists alone, so a column added
        // after the first release has to be added by hand.
        $this->addMissingColumns('channels', ['hd' => 'INTEGER NOT NULL DEFAULT 0', 'audio' => 'TEXT']);
        $this->addMissingColumns('atsc3_channels', [
            'source'     => "TEXT NOT NULL DEFAULT 'lineup'",
            'broadband'  => 'INTEGER NOT NULL DEFAULT 0',
            'stream_url' => 'TEXT',
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

    private function transaction(callable $work): void
    {
        $this->db->beginTransaction();

        try {
            $work();
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();

            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function castChannel(array $row): array
    {
        return [
            'id'        => (int) $row['id'],
            'device'    => $row['device'],
            'physical'  => (int) $row['physical'],
            'program'   => (int) $row['program'],
            'virtual'   => $row['virtual'],
            'name'      => $row['name'],
            'tsid'      => $row['tsid'] === null ? null : (int) $row['tsid'],
            'sourceId'  => $row['source_id'] === null ? null : (int) $row['source_id'],
            'encrypted' => (bool) $row['encrypted'],
            'hd'        => (bool) ($row['hd'] ?? false),
            'audio'     => is_string($row['audio'] ?? null) ? (json_decode($row['audio'], true) ?: []) : [],
            'updatedAt' => (int) $row['updated_at'],
        ];
    }

    /**
     * The audio streams of a programme, as the analyser rendered them.
     *
     * The layout comes from the bitstream itself ("48 kHz, 5.1 (3/2 + LFE), bsid 6"), so
     * a player can say 5.1 or Stereo rather than a track number. Everything is converted
     * to stereo for browsers, which is why the playlist cannot be asked instead.
     *
     * @param list<array<string, mixed>> $streams every elementary stream of the programme
     * @return list<array{language: ?string, name: ?string, channels: ?string}>
     */
    private static function audioTracks(array $streams): array
    {
        $audio = [];

        foreach ($streams as $stream) {
            if (stripos((string) ($stream['typeName'] ?? ''), 'audio') !== false) {
                $audio[] = [
                    'language' => $stream['language'] ?? null,
                    'name'     => $stream['typeName'] ?? null,
                    'channels' => self::channelLabel((string) ($stream['bitstream'] ?? '')),
                ];
            }
        }

        return $audio;
    }

    /**
     * The "5.1" or "2.0" part of an AC-3 summary, when it says one.
     */
    private static function channelLabel(string $bitstream): ?string
    {
        return preg_match('/(\d+\.\d+)/', $bitstream, $match) === 1 ? $match[1] : null;
    }

    /**
     * Sort key for "4.10" after "4.9".
     *
     * @return int[]
     */
    private static function virtualKey(string $virtual): array
    {
        return array_map('intval', explode('.', $virtual)) + [0, 0];
    }
}
