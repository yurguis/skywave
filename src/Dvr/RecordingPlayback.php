<?php

declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave\Dvr;

use RuntimeException;

/**
 * Plays recordings back in a browser.
 *
 * An mp4 recording already plays as it is and only needs serving. A ts recording holds the
 * broadcast untouched, which no browser can decode (MPEG-2 video, AC-3 audio), so it is
 * converted to HLS on demand: one ffmpeg per recording being watched, writing an EVENT
 * playlist that grows as it goes. Conversion runs far faster than playback, so a viewer
 * can start within seconds and seek anywhere already converted.
 *
 * Sessions live beside the recordings, not in the tmpfs the live streams use: an hour of
 * video does not belong in memory.
 *
 *   <recordings>/.playback/<recording id>/index.m3u8   playlist, plus seg_NNNNN.ts
 *   <recordings>/.playback/<recording id>/session.json ffmpeg's pid and who is watching
 */
class RecordingPlayback
{
    private const SEGMENT_SECONDS = 4;

    /** More languages than this on one programme is not something to plan for. */
    private const MAXIMUM_AUDIO_TRACKS = 4;

    private RecordingStore $store;
    private string $directory;
    private string $ffmpeg;
    private int $height;
    private int $viewerTimeout;

    public function __construct(RecordingStore $store, string $recordingsDirectory, string $ffmpeg = 'ffmpeg', int $height = 720, int $viewerTimeout = 60)
    {
        $this->store         = $store;
        $this->directory     = rtrim($recordingsDirectory, '/') . '/.playback';
        $this->ffmpeg        = $ffmpeg;
        $this->height        = max(144, min(2160, $height));
        $this->viewerTimeout = max(15, $viewerTimeout);
    }

    /**
     * Settings from RECORDINGS_DIR, FFMPEG, RECORDING_PLAYBACK_HEIGHT and HLS_VIEWER_TIMEOUT.
     */
    public static function fromEnvironment(): self
    {
        $env = static function (string $name, string $default): string {
            $value = getenv($name);

            return $value === false || $value === '' ? $default : $value;
        };

        return new self(
            RecordingStore::fromEnvironment(),
            $env('RECORDINGS_DIR', dirname(__DIR__, 2) . '/data/recordings'),
            $env('FFMPEG', 'ffmpeg'),
            (int) $env('RECORDING_PLAYBACK_HEIGHT', '720'),
            (int) $env('HLS_VIEWER_TIMEOUT', '60')
        );
    }

    /**
     * Start watching, converting the recording first when a browser cannot play it as it is.
     *
     * @return array<string, mixed>
     */
    public function play(int $recordingId, string $viewer): array
    {
        $recording = $this->recording($recordingId);

        if (self::playsAsIs($recording)) {
            return $this->describeFile($recording);
        }

        return $this->locked(function () use ($recording, $viewer): array {
            $this->reap();

            $session = $this->load($recording['id']);

            if ($session !== null && !DetachedProcess::isRunning((int) $session['pid'])) {
                // Converted to the end, or stopped early; either way its files stay usable.
                $session['endedAt'] ??= time();
            }

            if ($session === null) {
                $session = $this->start($recording);
            }

            $session['viewers'][$viewer] = time();
            $this->save($session);

            return $this->describe($session, $recording);
        });
    }

    /**
     * Where a session has got to; also records that $viewer is still watching.
     *
     * @return array<string, mixed>
     */
    public function status(int $recordingId, ?string $viewer): array
    {
        $recording = $this->recording($recordingId);

        if (self::playsAsIs($recording)) {
            return $this->describeFile($recording);
        }

        return $this->locked(function () use ($recording, $viewer): array {
            $this->reap();
            $session = $this->load($recording['id']);

            if ($session === null) {
                throw new RuntimeException('That playback has stopped');
            }

            if ($viewer !== null) {
                $session['viewers'][$viewer] = time();
                $this->save($session);
            }

            return $this->describe($session, $recording);
        });
    }

    /**
     * @return array{stopped: bool}
     */
    public function leave(int $recordingId, string $viewer): array
    {
        return $this->locked(function () use ($recordingId, $viewer): array {
            $session = $this->load($recordingId);

            if ($session === null) {
                return ['stopped' => true];
            }

            unset($session['viewers'][$viewer]);

            if ($session['viewers'] === []) {
                $this->terminate($session);

                return ['stopped' => true];
            }

            $this->save($session);

            return ['stopped' => false];
        });
    }

    /**
     * Path of a playlist or segment to serve, or null when it is not one of ours.
     */
    public function resolveFile(int $recordingId, string $file): ?string
    {
        // index.m3u8 is the playlist, or the master listing one per language; seg_ files
        // come from a recording with a single track, v0/v1 from one with several.
        if ($recordingId < 1 || !preg_match('/^(index\.m3u8|seg_\d{5}\.ts|v\d+\.m3u8|v\d+_\d{5}\.ts)$/', $file)) {
            return null;
        }

        $path = "$this->directory/$recordingId/$file";

        return is_file($path) ? $path : null;
    }

    /**
     * The recording's own file, for serving an mp4 or downloading the original.
     */
    /**
     * The captions written beside a converted recording, when there are any.
     *
     * A browser shows captions carried inside a playlist but not inside a plain file, so a
     * converted recording keeps them in a WebVTT file the player attaches as a track.
     */
    public function captionsFile(int $recordingId): ?string
    {
        $file = $this->sourceFile($recordingId);

        if ($file === null || !str_ends_with($file, '.mp4')) {
            return null;
        }

        $captions = Recorder::captionsPath($file);

        // ffmpeg writes a WebVTT header even for a programme with no captions in it.
        return is_file($captions) && filesize($captions) > 0 ? $captions : null;
    }

    public function sourceFile(int $recordingId): ?string
    {
        $recording = $this->store->getRecording($recordingId);

        if ($recording === null) {
            return null;
        }

        // A "both" recording keeps the broadcast and a browser-ready copy; serve the copy.
        $path = rtrim(dirname($this->directory), '/') . '/' . ($recording['convertedPath'] ?? $recording['path']);

        return is_file($path) ? $path : null;
    }

    /**
     * True when a browser can play the recording without any conversion.
     *
     * @param array<string, mixed> $recording
     */
    private static function playsAsIs(array $recording): bool
    {
        return $recording['format'] === 'mp4' || ($recording['convertedPath'] ?? null) !== null;
    }

    /**
     * Throw away everything a recording's playback left behind, e.g. before deleting it.
     */
    public function forget(int $recordingId): void
    {
        $this->locked(function () use ($recordingId): void {
            $session = $this->load($recordingId);

            if ($session !== null) {
                $this->terminate($session);

                return;
            }

            self::removeDirectory("$this->directory/$recordingId");
        });
    }

    /**
     * @param array<string, mixed> $recording
     * @return array<string, mixed>
     */
    private function start(array $recording): array
    {
        $source = rtrim(dirname($this->directory), '/') . '/' . $recording['path'];

        if (!is_file($source)) {
            throw new RuntimeException('The recording file is missing; the drive may be disconnected');
        }

        $directory = "$this->directory/{$recording['id']}";
        self::removeDirectory($directory);

        if (!@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException("Unable to create $directory");
        }

        $pid = DetachedProcess::start($this->ffmpegArguments($source, $directory), "$directory/ffmpeg.log");

        return [
            'recordingId' => $recording['id'],
            'pid'         => $pid,
            'startedAt'   => time(),
            'endedAt'     => null,
            'viewers'     => [],
        ];
    }

    /**
     * The program's audio tracks, in the order ffmpeg will map them.
     *
     * A broadcast often carries a second language on its own track, and a recording keeps
     * every one of them. They have to be counted before ffmpeg starts: naming a track that
     * is not there makes it write nothing at all.
     *
     * @return list<array{language: ?string, channels: ?int}>
     */
    private function audioTracks(string $file): array
    {
        $probe = str_replace('ffmpeg', 'ffprobe', $this->ffmpeg);
        $shown = (string) shell_exec(sprintf(
            '%s -v error -select_streams a -show_entries stream=channels:stream_tags=language -of json %s 2>/dev/null',
            escapeshellarg($probe),
            escapeshellarg($file)
        ));
        $probed = json_decode($shown, true);
        $tracks = [];

        foreach (array_slice($probed['streams'] ?? [], 0, self::MAXIMUM_AUDIO_TRACKS) as $stream) {
            $count = isset($stream['channels']) ? (int) $stream['channels'] : null;

            $tracks[] = [
                'language' => $stream['tags']['language'] ?? null,
                'channels' => $count === null ? null : self::channelLabel($count),
            ];
        }

        return $tracks;
    }

    /**
     * A count of channels as people write it: 6 is 5.1, 2 is 2.0.
     */
    private static function channelLabel(int $channels): string
    {
        return $channels > 2 ? sprintf('%d.1', $channels - 1) : sprintf('%d.0', $channels);
    }

    /**
     * @return string[]
     */
    private function ffmpegArguments(string $source, string $directory): array
    {
        $tracks = $this->audioTracks($source);

        // Nothing to choose between: keep the simple single playlist the player already
        // knows, rather than a master playlist describing one of everything.
        if (count($tracks) < 2) {
            return $this->singleTrackArguments($source, $directory);
        }

        $maps     = [];
        $variants = ['v:0,agroup:aud'];

        foreach ($tracks as $index => $track) {
            $maps[]  = '-map';
            $maps[]  = "0:a:$index";
            $variant = "a:$index,agroup:aud";

            if ($track['language'] !== null) {
                $variant .= ',language:' . $track['language'];
            }

            $variants[] = $variant . ($index === 0 ? ',default:yes' : '');
        }

        return array_merge([
            $this->ffmpeg, '-hide_banner', '-nostdin', '-loglevel', 'error',
            '-fflags', '+genpts+discardcorrupt',
            '-i', $source,
            '-map', '0:v:0',
        ], $maps, [
            '-vf', sprintf('estdif=mode=frame:deint=interlaced,scale=w=-2:h=trunc(min(%d\,ih)/2)*2', $this->height),
            '-fps_mode', 'passthrough',
            '-c:v', 'libx264', '-preset', 'veryfast', '-pix_fmt', 'yuv420p', '-crf', '21',
            // Downmixed to stereo. A browser's media source does not reliably decode
            // 5.1 AAC: passing surround through left every channel that broadcasts it
            // stuck at buffering, with segments written and no error to show for it.
            // Converting a recording to a file keeps 5.1, because that is not played
            // through a browser.
            '-c:a', 'aac', '-ac', '2',
            '-f', 'hls',
            '-hls_time', (string) self::SEGMENT_SECONDS,
            '-hls_playlist_type', 'event',
            '-hls_flags', 'independent_segments',
            // Each language becomes its own playlist in one audio group, which is how a
            // player is able to offer them.
            '-var_stream_map', implode(' ', $variants),
            '-master_pl_name', 'index.m3u8',
            '-hls_segment_filename', "$directory/v%v_%05d.ts",
            "$directory/v%v.m3u8",
        ]);
    }

    /**
     * @return string[]
     */
    private function singleTrackArguments(string $source, string $directory): array
    {
        return [
            $this->ffmpeg, '-hide_banner', '-nostdin', '-loglevel', 'error',
            '-fflags', '+genpts+discardcorrupt',
            '-i', $source,
            '-map', '0:v:0', '-map', '0:a:0',
            // Deinterlace a frame at a time, as live playback does, so the closed captions
            // that travel with each frame survive. Progressive material passes through.
            '-vf', sprintf('estdif=mode=frame:deint=interlaced,scale=w=-2:h=trunc(min(%d\,ih)/2)*2', $this->height),
            '-fps_mode', 'passthrough',
            '-c:v', 'libx264', '-preset', 'veryfast', '-pix_fmt', 'yuv420p', '-crf', '21',
            // Downmixed to stereo. A browser's media source does not reliably decode
            // 5.1 AAC: passing surround through left every channel that broadcasts it
            // stuck at buffering, with segments written and no error to show for it.
            // Converting a recording to a file keeps 5.1, because that is not played
            // through a browser.
            '-c:a', 'aac', '-ac', '2',
            '-f', 'hls',
            '-hls_time', (string) self::SEGMENT_SECONDS,
            // An event playlist only grows, so the viewer can seek across everything
            // converted so far while the rest is still being written.
            '-hls_playlist_type', 'event',
            '-hls_flags', 'independent_segments',
            '-hls_segment_filename', "$directory/seg_%05d.ts",
            "$directory/index.m3u8",
        ];
    }

    /**
     * @param array<string, mixed> $session
     * @param array<string, mixed> $recording
     * @return array<string, mixed>
     */
    private function describe(array $session, array $recording): array
    {
        $directory = "$this->directory/{$recording['id']}";
        // With several languages index.m3u8 is a master playlist naming the others, and
        // the segments are counted in the video one.
        $playlist = @file_get_contents("$directory/v0.m3u8");
        $playlist = $playlist === false ? @file_get_contents("$directory/index.m3u8") : $playlist;
        $segments = $playlist === false ? 0 : substr_count($playlist, '#EXTINF');
        $running  = DetachedProcess::isRunning((int) $session['pid']);

        return [
            'kind'        => 'hls',
            'recordingId' => $recording['id'],
            'title'       => $recording['title'],
            // The channel number on its own, so the player can show that channel's logo.
            'virtual'    => $recording['virtual'],
            'subtitle'   => trim("{$recording['virtual']} {$recording['channelName']}"),
            'playlist'   => "/recordings/{$recording['id']}/hls/index.m3u8",
            'converting' => $running,
            // Two segments in, there is enough to start without stalling straight away.
            'ready'    => $segments >= 2,
            'segments' => $segments,
            'viewers'  => count($session['viewers']),
            // What each track was before it was converted, so the player can name them.
            'audio' => $this->audioTracks(rtrim(dirname($this->directory), '/') . '/' . $recording['path']),
            'error' => $running || $segments > 0 ? null : (DetachedProcess::lastLogLine("$directory/ffmpeg.log") ?? 'The converter stopped'),
        ];
    }

    /**
     * An mp4 recording needs no conversion: the browser plays the file itself.
     *
     * @param array<string, mixed> $recording
     * @return array<string, mixed>
     */
    private function describeFile(array $recording): array
    {
        return [
            'kind'        => 'file',
            'recordingId' => $recording['id'],
            'title'       => $recording['title'],
            'virtual'     => $recording['virtual'],
            'subtitle'    => trim("{$recording['virtual']} {$recording['channelName']}"),
            'url'         => "/recordings/{$recording['id']}/file",
            // Only when the conversion actually found captions to write.
            'captions' => $this->captionsFile($recording['id']) === null
                ? null
                : "/recordings/{$recording['id']}/captions.vtt",
            'converting' => false,
            'ready'      => true,
            'segments'   => 0,
            'viewers'    => 1,
            'error'      => null,
        ];
    }

    /**
     * Stop converting for viewers who have gone away, and clear up after them.
     */
    private function reap(): void
    {
        $now = time();

        foreach (glob("$this->directory/*/session.json") ?: [] as $file) {
            $session = $this->load((int) basename(dirname($file)));

            if ($session === null) {
                continue;
            }

            $watching = array_filter($session['viewers'], fn (int $seen) => $now - $seen <= $this->viewerTimeout);

            if ($watching === []) {
                $this->terminate($session);
            } elseif (count($watching) !== count($session['viewers'])) {
                $session['viewers'] = $watching;
                $this->save($session);
            }
        }
    }

    /**
     * @param array<string, mixed> $session
     */
    private function terminate(array $session): void
    {
        DetachedProcess::stop((int) $session['pid']);
        self::removeDirectory("$this->directory/{$session['recordingId']}");
    }

    /**
     * @return array<string, mixed>
     */
    private function recording(int $recordingId): array
    {
        $recording = $this->store->getRecording($recordingId);

        if ($recording === null) {
            throw new RuntimeException("No recording with id $recordingId");
        }

        if ($recording['status'] === RecordingStore::STATUS_RECORDING) {
            throw new RuntimeException('That recording is still running; watch the channel live instead');
        }

        if ($recording['bytes'] < 1) {
            throw new RuntimeException('That recording is empty');
        }

        return $recording;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function load(int $recordingId): ?array
    {
        $json    = @file_get_contents("$this->directory/$recordingId/session.json");
        $session = $json === false ? null : json_decode($json, true);

        return is_array($session) ? $session : null;
    }

    /**
     * @param array<string, mixed> $session
     */
    private function save(array $session): void
    {
        $file = "$this->directory/{$session['recordingId']}/session.json";

        file_put_contents("$file.tmp", json_encode($session, JSON_PRETTY_PRINT));
        rename("$file.tmp", $file);
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function locked(callable $callback)
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
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
