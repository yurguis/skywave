<?php declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave\Web;

use RuntimeException;
use Skywave\Hdhomerun\ControlClient;
use Skywave\Hdhomerun\Tuner;

/**
 * Live playback sessions: one ffmpeg process per device, tuner, channel and program,
 * turning the tuner's HTTP stream into HLS (H.264 + AAC) that browsers can play.
 *
 * Sessions live on disk so every PHP worker sees the same state:
 *   <dir>/<id>/session.json   stream details, the ffmpeg pid and who is watching
 *   <dir>/<id>/index.m3u8     rolling playlist, plus seg_NNNNN.ts segments
 *   <dir>/<id>/ffmpeg.log     transcoder errors
 *
 * Viewers keep a session alive by asking for its status. A session is stopped once all
 * its viewers have been quiet for the viewer timeout, and forgotten a timeout after its
 * ffmpeg exits; both happen whenever a request touches the streams.
 */
class LiveStreams
{
    private const HTTP_STREAM_PORT = 5004;
    private const SIGTERM          = 15;
    private const SIGKILL          = 9;

    private const SEGMENT_SECONDS = 2;
    private const MAX_RENDITIONS  = 4;

    /** More languages than this on one programme is not something to plan for. */
    private const MAX_AUDIO_TRACKS = 4;

    /** Long enough for ffmpeg to refuse a missing track or write its playlist. */
    private const AUDIO_CHECK_SECONDS = 1.5;

    private string $directory;
    private int $maxStreams;
    private int $viewerTimeout;
    /** @var int[] picture heights, tallest first */
    private array $renditions;
    private string $ffmpeg;
    private int $rewindSeconds;

    /**
     * @param int[] $renditions    picture heights to offer, e.g. [720, 480, 360]; players switch
     *                             between them as the connection allows
     * @param int   $rewindMinutes how far back viewers can go in a live stream
     */
    public function __construct(string $directory, int $maxStreams = 2, int $viewerTimeout = 30, array $renditions = [720, 480, 360], string $ffmpeg = 'ffmpeg', int $rewindMinutes = 5)
    {
        $heights = array_values(array_unique(array_map(fn($height) => max(144, min(2160, (int) $height)), $renditions)));
        rsort($heights);

        $this->directory     = rtrim($directory, '/');
        $this->maxStreams    = max(1, $maxStreams);
        $this->viewerTimeout = max(5, $viewerTimeout);
        $this->renditions    = array_slice($heights === [] ? [720] : $heights, 0, self::MAX_RENDITIONS);
        $this->ffmpeg        = $ffmpeg;
        $this->rewindSeconds = max(1, $rewindMinutes) * 60;
    }

    /**
     * Settings from HLS_DIR, MAX_STREAMS, HLS_VIEWER_TIMEOUT, HLS_RENDITIONS, HLS_DVR_MINUTES
     * and FFMPEG.
     */
    public static function fromEnvironment(): self
    {
        $env = static function (string $name, string $default): string {
            $value = getenv($name);

            return $value === false || $value === '' ? $default : $value;
        };

        return new self(
            $env('HLS_DIR', sys_get_temp_dir() . '/hdhomerun-hls'),
            (int) $env('MAX_STREAMS', '2'),
            (int) $env('HLS_VIEWER_TIMEOUT', '30'),
            array_map('intval', array_filter(array_map('trim', explode(',', $env('HLS_RENDITIONS', '720,480,360'))), 'ctype_digit')),
            $env('FFMPEG', 'ffmpeg'),
            (int) $env('HLS_DVR_MINUTES', '5')
        );
    }

    public function getDirectory(): string
    {
        return $this->directory;
    }

    /**
     * Watch a program on a tuned tuner, starting its transcoder if nobody else is.
     * Another program already streaming from the same tuner is stopped first.
     *
     * @return array<string, mixed>
     */
    /**
     * @param list<array{language: ?string, name: ?string}> $audioTracks the programme's audio
     *        tracks as the guide last saw them; several means the viewer can choose
     */
    public function join(string $host, int $tuner, string $channel, int $physicalChannel, int $program, string $viewer, string $targetBefore, array $audioTracks = []): array
    {
        return $this->locked(function () use ($host, $tuner, $channel, $physicalChannel, $program, $viewer, $targetBefore, $audioTracks): array {
            $this->reap();

            $id      = self::sessionId($host, $tuner, $physicalChannel, $program);
            $session = $this->load($id);

            if ($session !== null && !$this->isRunning($session['pid'])) {
                $this->terminate($session, false);
                $session = null;
            }

            if ($session === null) {
                foreach ($this->loadAll() as $other) {
                    if ($other['host'] === $host && $other['tuner'] === $tuner) {
                        // The device streams one channel per tuner; switching replaces it.
                        $this->terminate($other, false);
                        $targetBefore = $other['targetBefore'];
                    }
                }

                if (count($this->loadAll()) >= $this->maxStreams) {
                    throw new ApiException("Already playing $this->maxStreams streams, the most allowed (MAX_STREAMS)", 409);
                }

                $session = $this->start($id, $host, $tuner, $channel, $physicalChannel, $program, $targetBefore, $audioTracks);

                // The guide's idea of a programme's audio can be out of date: a broadcaster
                // drops a second language and ffmpeg, asked for a track that is not there,
                // writes nothing at all. Rather than leave a stream that never starts, take
                // the programme at its word and keep the first track.
                if ($audioTracks !== [] && $this->audioTrackMissing("$this->directory/$id", (int) $session['pid'])) {
                    $this->terminate($session, false);
                    $session = $this->start($id, $host, $tuner, $channel, $physicalChannel, $program, $targetBefore);
                }
            }

            $session['viewers'][$viewer] = time();
            $this->save($session);

            return $this->describe($session);
        });
    }

    /**
     * Current state of a session; also records that $viewer is still watching.
     *
     * @return array<string, mixed>
     */
    public function status(string $id, ?string $viewer): array
    {
        return $this->locked(function () use ($id, $viewer): array {
            $this->reap();
            $session = $this->load($id);

            if ($session === null) {
                throw new ApiException('Stream not found; it has stopped', 404);
            }

            if ($viewer !== null && $session['endedAt'] === null) {
                $session['viewers'][$viewer] = time();
                $this->save($session);
            }

            return $this->describe($session);
        });
    }

    /**
     * Stop watching. The transcoder stops when its last viewer leaves.
     *
     * @return array{stopped: bool}
     */
    public function leave(string $id, string $viewer): array
    {
        return $this->locked(function () use ($id, $viewer): array {
            $session = $this->load($id);

            if ($session === null) {
                return ['stopped' => true];
            }

            unset($session['viewers'][$viewer]);

            if ($session['viewers'] === []) {
                $this->terminate($session, true);

                return ['stopped' => true];
            }

            $this->save($session);

            return ['stopped' => false];
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return $this->locked(function (): array {
            $this->reap();

            return array_map(fn(array $session) => $this->describe($session), $this->loadAll());
        });
    }

    /**
     * Path of a playlist or segment file for serving, or null when it is not one.
     */
    public function resolveFile(string $id, string $file): ?string
    {
        if (!self::isValidId($id) || !preg_match('/^(index\.m3u8|v\d\.m3u8|v\d_\d{5}\.ts)$/', $file)) {
            return null;
        }

        $path = "$this->directory/$id/$file";

        return is_file($path) ? $path : null;
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @param list<array{language: ?string, name: ?string}> $audioTracks
     * @return array<string, mixed>
     */
    private function start(string $id, string $host, int $tuner, string $channel, int $physicalChannel, int $program, string $targetBefore, array $audioTracks = []): array
    {
        $directory = "$this->directory/$id";
        self::removeDirectory($directory);

        if (!@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException("Unable to create $directory");
        }

        $source    = sprintf('http://%s:%d/tuner%d/ch%d', $host, self::HTTP_STREAM_PORT, $tuner, $physicalChannel);
        $arguments = array_map('escapeshellarg', $this->ffmpegArguments($source, $program, $directory, $audioTracks));

        // setsid detaches ffmpeg from this PHP worker so it outlives the request; its pid
        // is also its process group, which is what terminate() signals.
        $pid = (int) trim((string) shell_exec(sprintf(
            'setsid %s > %s 2>&1 < /dev/null & echo $!',
            implode(' ', $arguments),
            escapeshellarg("$directory/ffmpeg.log")
        )));

        if ($pid <= 0) {
            throw new RuntimeException('Unable to start ffmpeg');
        }

        return [
            'id'              => $id,
            'host'            => $host,
            'tuner'           => $tuner,
            'channel'         => $channel,
            'physicalChannel' => $physicalChannel,
            'program'         => $program,
            'targetBefore'    => $targetBefore,
            'pid'             => $pid,
            'renditions'      => count($this->renditions),
            // Kept so the player can name each track; the playlist only ever says stereo,
            // because that is what every one of them is converted to.
            'audio'           => array_values($audioTracks),
            'startedAt'       => time(),
            'endedAt'         => null,
            'viewers'         => [],
        ];
    }

    /**
     * @return string[]
     */
    /**
     * @param list<array{language: ?string, name: ?string}> $audioTracks
     * @return string[]
     */
    private function ffmpegArguments(string $source, int $program, string $directory, array $audioTracks = []): array
    {
        $top   = $this->renditions[0];
        $count = count($this->renditions);
        // Naming a track the programme does not carry makes ffmpeg write nothing at all,
        // so only what the guide actually saw is asked for.
        $tracks = array_slice($audioTracks, 0, self::MAX_AUDIO_TRACKS);

        // The stream carries the whole multiplex: take one program's first video, decode and
        // deinterlace it once, then scale a copy per rendition. estdif deinterlaces from a
        // single frame; temporal deinterlacers (yadif, bwdif) scramble and delay the closed
        // captions that travel with each frame.
        $graph   = ["[0:p:$program:v:0]estdif=mode=frame:deint=interlaced,split=$count"
            . implode('', array_map(fn(int $i) => "[s$i]", array_keys($this->renditions)))];
        $outputs = [];
        $streams = [];

        foreach ($this->renditions as $i => $height) {
            // Lower renditions shrink in proportion to the source, so an SD channel gets
            // 480/320/240 rather than three copies of 480.
            $graph[]   = sprintf('[s%d]scale=w=-2:h=trunc(min(%d\,ih*%d/%d)/2)*2[v%d]', $i, $height, $height, $top, $i);
            $maxrate   = self::maxBitrate($height);
            $outputs   = array_merge($outputs, [
                '-map', "[v$i]",
                "-maxrate:v:$i", "{$maxrate}k", "-bufsize:v:$i", ($maxrate * 2) . 'k',
            ]);
            // With several languages every rendition shares one audio group, which also
            // stops the same track being encoded once per rendition.
            $streams[] = count($tracks) > 1 ? "v:$i,agroup:aud" : "v:$i,a:$i";

            if (count($tracks) <= 1) {
                $outputs = array_merge($outputs, ['-map', "0:p:$program:a:0", "-b:a:$i", $height >= 480 ? '128k' : '96k']);
            }
        }

        foreach (count($tracks) > 1 ? $tracks : [] as $i => $track) {
            $outputs   = array_merge($outputs, ['-map', "0:p:$program:a:$i", "-b:a:$i", '128k']);
            $language  = $track['language'] ?? null;
            $streams[] = "a:$i,agroup:aud"
                . ($language === null ? '' : ",language:$language")
                . ($i === 0 ? ',default:yes' : '');
        }

        return array_merge([
            $this->ffmpeg, '-hide_banner', '-nostdin', '-loglevel', 'error',
            '-fflags', '+genpts+discardcorrupt',
            '-i', $source,
            '-filter_complex', implode(';', $graph),
        ], $outputs, [
            // Keep frames as broadcast. HLS output otherwise defaults to constant frame rate,
            // which duplicates frames of 24 fps film sent as 30i, and with them the closed
            // captions each frame carries ("was was", "tryingying").
            '-fps_mode', 'passthrough',
            // Constant quality, capped per rendition so a busy scene cannot outgrow the
            // connection it was picked for; quieter pictures use less.
            '-c:v', 'libx264', '-preset', 'veryfast', '-pix_fmt', 'yuv420p', '-crf', '21',
            // Key frames at the same instants in every rendition, so players can switch
            // between them at any segment.
            '-force_key_frames', 'expr:gte(t,n_forced*2)', '-sc_threshold', '0',
            '-c:a', 'aac', '-ac', '2',
            // Each playlist keeps the rewind window; older segments are deleted.
            '-f', 'hls', '-hls_time', (string) self::SEGMENT_SECONDS,
            '-hls_list_size', (string) max(10, intdiv($this->rewindSeconds, self::SEGMENT_SECONDS)),
            '-hls_flags', 'delete_segments+independent_segments+omit_endlist+temp_file',
            '-var_stream_map', implode(' ', $streams),
            '-master_pl_name', 'index.m3u8',
            '-hls_segment_filename', "$directory/v%v_%05d.ts",
            "$directory/v%v.m3u8",
        ]);
    }

    /**
     * Highest video bitrate for a picture height, in kbit/s.
     */
    private static function maxBitrate(int $height): int
    {
        if ($height > 720) {
            return 7000;
        }

        if ($height > 540) {
            return 3500;
        }

        return $height > 360 ? 1500 : ($height > 240 ? 800 : 450);
    }

    /**
     * Stop sessions nobody watches and forget ones whose transcoder has exited.
     */
    private function reap(): void
    {
        $now = time();

        foreach ($this->loadAll() as $session) {
            if (!$this->isRunning($session['pid'])) {
                if ($session['endedAt'] === null) {
                    $session['endedAt'] = $now;
                    $this->save($session);
                } elseif ($now - $session['endedAt'] > $this->viewerTimeout) {
                    $this->terminate($session, true);
                }

                continue;
            }

            $active = array_filter($session['viewers'], fn(int $seen) => $now - $seen <= $this->viewerTimeout);

            if ($active === []) {
                $this->terminate($session, true);
            } elseif (count($active) !== count($session['viewers'])) {
                $session['viewers'] = $active;
                $this->save($session);
            }
        }
    }

    /**
     * @param array<string, mixed> $session
     */
    private function terminate(array $session, bool $restoreTuner): void
    {
        $pid = $session['pid'];

        if ($this->isRunning($pid)) {
            self::signal($pid, self::SIGTERM);

            for ($i = 0; $i < 20 && $this->isRunning($pid); $i++) {
                usleep(100000);
            }

            if ($this->isRunning($pid)) {
                self::signal($pid, self::SIGKILL);
            }
        }

        self::removeDirectory("$this->directory/{$session['id']}");

        if ($restoreTuner) {
            $tuner = new Tuner(new ControlClient($session['host'], 65001, 2.0), $session['tuner']);
            TunerRelease::restoreChannel($tuner, $session['channel'], $session['targetBefore']);
        }
    }

    /**
     * @param array<string, mixed> $session
     * @return array<string, mixed>
     */
    private function describe(array $session): array
    {
        $directory = "$this->directory/{$session['id']}";
        $alive     = $session['endedAt'] === null && $this->isRunning($session['pid']);
        $segments  = is_file("$directory/index.m3u8") ? PHP_INT_MAX : 0;

        // Ready once every rendition has something to play, so switching never stalls.
        for ($i = 0; $i < ($session['renditions'] ?? 1); $i++) {
            $playlist = @file_get_contents("$directory/v$i.m3u8");
            $segments = min($segments, $playlist === false ? 0 : substr_count($playlist, '#EXTINF'));
        }

        return [
            'id'        => $session['id'],
            'host'      => $session['host'],
            'tuner'     => $session['tuner'],
            'channel'   => $session['channel'],
            'program'   => $session['program'],
            'playlist'  => "/hls/{$session['id']}/index.m3u8",
            'alive'     => $alive,
            'ready'     => $alive && $segments >= 2,
            'segments'  => $segments,
            'viewers'   => count($session['viewers']),
            'startedAt' => date(DATE_ATOM, $session['startedAt']),
            'audio'     => $session['audio'] ?? [],
            'error'     => $alive ? null : (self::lastLogLine("$directory/ffmpeg.log") ?? 'The transcoder stopped'),
        ];
    }

    private function isRunning(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        if (is_dir('/proc/self')) {
            // Make sure the pid still belongs to an ffmpeg that has not exited.
            $cmdline = @file_get_contents("/proc/$pid/cmdline");
            $stat    = @file_get_contents("/proc/$pid/stat");

            return $cmdline !== false && str_contains($cmdline, 'ffmpeg') && !preg_match('/\) [ZX] /', (string) $stat);
        }

        return function_exists('posix_kill') && posix_kill($pid, 0);
    }

    private static function signal(int $pid, int $signal): void
    {
        if (function_exists('posix_kill')) {
            posix_kill(-$pid, $signal);

            return;
        }

        exec(sprintf('kill -%d -- -%d 2>/dev/null', $signal, $pid));
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function locked(callable $callback)
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException("Unable to create $this->directory");
        }

        $handle = fopen("$this->directory/.lock", 'c');

        if ($handle === false || !flock($handle, LOCK_EX)) {
            throw new RuntimeException("Unable to lock $this->directory");
        }

        try {
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadAll(): array
    {
        $sessions = [];

        foreach (glob("$this->directory/*/session.json") ?: [] as $file) {
            $session = $this->load(basename(dirname($file)));

            if ($session !== null) {
                $sessions[] = $session;
            }
        }

        return $sessions;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function load(string $id): ?array
    {
        if (!self::isValidId($id)) {
            return null;
        }

        $json    = @file_get_contents("$this->directory/$id/session.json");
        $session = $json === false ? null : json_decode($json, true);

        return is_array($session) ? $session : null;
    }

    /**
     * @param array<string, mixed> $session
     */
    private function save(array $session): void
    {
        $file = "$this->directory/{$session['id']}/session.json";

        file_put_contents("$file.tmp", json_encode($session, JSON_PRETTY_PRINT));
        rename("$file.tmp", $file);
    }

    /**
     * Whether ffmpeg gave up over the audio tracks it was asked for.
     *
     * It refuses in two different ways: the map option itself is rejected when the track
     * is not in the programme at all, and the variant map is rejected when the track is
     * declared but never mapped. Matching either wording is fragile, so what really counts
     * is that it exited without writing a playlist. The messages only make that quicker
     * to notice.
     */
    private function audioTrackMissing(string $directory, int $pid): bool
    {
        $deadline = microtime(true) + self::AUDIO_CHECK_SECONDS;

        while (microtime(true) < $deadline) {
            if (is_file("$directory/index.m3u8")) {
                return false;
            }

            $log = @file_get_contents("$directory/ffmpeg.log");

            if ($log !== false && preg_match('/Unable to map stream|for option \'map\'/', $log)) {
                return true;
            }

            // Gone without a playlist: whatever it objected to, it is not going to play.
            if (!$this->isRunning($pid)) {
                return !is_file("$directory/index.m3u8");
            }

            usleep(20000);
        }

        // Still running and still starting: leave it alone, the session reports its own trouble.
        return false;
    }

    private static function sessionId(string $host, int $tuner, int $physicalChannel, int $program): string
    {
        return substr(hash('sha256', "$host|$tuner|$physicalChannel|$program"), 0, 16);
    }

    private static function isValidId(string $id): bool
    {
        return (bool) preg_match('/^[a-f0-9]{16}$/', $id);
    }

    /**
     * Last meaningful ffmpeg error. Probing the other programs in the multiplex logs
     * harmless decoder complaints that would otherwise hide the real cause.
     */
    private static function lastLogLine(string $file): ?string
    {
        $lines = array_filter(
            @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [],
            fn(string $line) => !preg_match('/Invalid frame dimensions 0x0|Last message repeated|corrupt decoded frame/', $line)
        );

        return $lines === [] ? null : (string) end($lines);
    }

    private static function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                @unlink("$directory/$entry");
            }
        }

        @rmdir($directory);
    }
}
