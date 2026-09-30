<?php

declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave\Dvr;

use RuntimeException;
use Skywave\AudioTracks;
use Skywave\HlsLadder;

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
 * Each size is written as one file rather than a numbered run of segments, with the
 * playlist pointing at byte ranges inside it. Three hours at four-second segments is 2,700
 * files per size otherwise, and the browser asks for a range either way.
 *
 *   <recordings>/.playback/<recording id>/index.m3u8   the sizes on offer
 *   <recordings>/.playback/<recording id>/v0.m3u8      one size, as ranges of v0.ts
 *   <recordings>/.playback/<recording id>/session.json ffmpeg's pid and who is watching
 */
class RecordingPlayback
{
    private const SEGMENT_SECONDS = 4;

    /** More sizes than this is more encoding than watching one recording is worth. */
    private const MAXIMUM_RENDITIONS = 4;

    private RecordingStore $store;
    private string $directory;
    private string $ffmpeg;
    /** @var int[] picture heights, tallest first; one of them means no choice to make */
    private array $renditions;
    private int $viewerTimeout;

    /**
     * @param int[] $renditions picture heights to offer, in any order
     */
    public function __construct(RecordingStore $store, string $recordingsDirectory, string $ffmpeg = 'ffmpeg', array $renditions = [720], int $viewerTimeout = 60)
    {
        $heights = array_values(array_unique(array_map(fn ($height) => max(144, min(2160, (int) $height)), $renditions)));
        rsort($heights);

        $this->store         = $store;
        $this->directory     = rtrim($recordingsDirectory, '/') . '/.playback';
        $this->ffmpeg        = $ffmpeg;
        $this->renditions    = array_slice($heights === [] ? [720] : $heights, 0, self::MAXIMUM_RENDITIONS);
        $this->viewerTimeout = max(15, $viewerTimeout);
    }

    /**
     * Settings from RECORDINGS_DIR, FFMPEG, RECORDING_PLAYBACK_RENDITIONS and
     * HLS_VIEWER_TIMEOUT.
     *
     * RECORDING_PLAYBACK_HEIGHT named the single size this used to make and still works as
     * one: a setting that was right before should not have to be rewritten to keep meaning
     * what it meant.
     */
    public static function fromEnvironment(): self
    {
        $env = static function (string $name, string $default): string {
            $value = getenv($name);

            return $value === false || $value === '' ? $default : $value;
        };

        $sizes = $env('RECORDING_PLAYBACK_RENDITIONS', $env('RECORDING_PLAYBACK_HEIGHT', '720'));

        return new self(
            RecordingStore::fromEnvironment(),
            $env('RECORDINGS_DIR', dirname(__DIR__, 2) . '/data/recordings'),
            $env('FFMPEG', 'ffmpeg'),
            array_map('intval', array_filter(array_map('trim', explode(',', $sizes)), 'ctype_digit')),
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

        $stored = $this->storedPlaylist($recording);
        if ($stored !== null) {
            return $this->describeStored($recording, $stored);
        }

        if ($this->playsAsIs($recording)) {
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

        $stored = $this->storedPlaylist($recording);
        if ($stored !== null) {
            return $this->describeStored($recording, $stored);
        }

        if ($this->playsAsIs($recording)) {
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
        // index.m3u8 is the playlist, or the master listing one per size and language; seg
        // comes from a recording with a single track and size, v0/v1 from one with several.
        //
        // The numbered forms are what a session written before segments were kept in one
        // file looks like. They are still served so that a recording somebody is watching
        // across an upgrade does not stop halfway through.
        $names = '/^(index\.m3u8|seg\.ts|seg_\d{5}\.ts|v\d+\.m3u8|v\d+\.ts|v\d+_\d{5}\.ts)$/';

        if ($recordingId < 1 || !preg_match($names, $file)) {
            return null;
        }

        $path = "$this->directory/$recordingId/$file";

        if (is_file($path)) {
            return $path;
        }

        // A copy converted ahead of time holds the same names, in its own directory beside
        // the recording. Once one exists no session is ever started, so the two never both
        // answer; this is second because it costs a database read and a segment is asked for
        // over and over, where the line above is a file test.
        $stored = $this->storedPlaylist($this->store->getRecording($recordingId) ?? []);

        return $stored !== null && is_file("$stored/$file") ? "$stored/$file" : null;
    }

    /**
     * The directory of a copy converted ahead of time, or null when there is not one.
     *
     * RECORDING_CONVERT_TO=hls makes this instead of an mp4: a finished playlist the player
     * opens directly, so the length and the seek bar are there from the first frame and
     * nothing has to be transcoded while somebody watches.
     *
     * @param array<string, mixed> $recording
     */
    private function storedPlaylist(array $recording): ?string
    {
        $path = $recording['convertedPath'] ?? null;

        if ($path === null || !str_ends_with($path, '.hls')) {
            return null;
        }

        $directory = rtrim(dirname($this->directory), '/') . '/' . $path;

        return is_file("$directory/index.m3u8") ? $directory : null;
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
        // An HLS copy is a directory and is served through its playlist instead, so what is
        // left to hand over whole is the broadcast -- which is what a download wants.
        $directory = rtrim(dirname($this->directory), '/');
        $converted = $recording['convertedPath'] ?? null;
        $path      = "$directory/" . ($converted === null || str_ends_with($converted, '.hls')
            ? $recording['path']
            : $converted);

        return is_file($path) ? $path : null;
    }

    /**
     * True when a browser can play the recording without any conversion.
     *
     * @param array<string, mixed> $recording
     */
    private function playsAsIs(array $recording): bool
    {
        $converted = (string) ($recording['convertedPath'] ?? '');

        // An HLS copy is a playlist and a directory of segments, not a file a <video> can be
        // pointed at. One that is there is used before this is asked; one that is damaged or
        // half-deleted leaves the broadcast, which is converted on demand as it always was.
        if ($recording['format'] !== 'mp4' && ($converted === '' || str_ends_with($converted, '.hls'))) {
            return false;
        }

        // Only when there is nothing to choose between. Outside Safari a plain <video>
        // gives no way to change audio track, so a file carrying a second language or an
        // audio description played whichever ffmpeg wrote first and offered no way back:
        // converting a recording quietly cost the viewer the choice. Those go through the
        // playlist instead, which can name every track, at the price of transcoding again
        // while it plays.
        //
        // The file that would be served carries the same tracks as the one read here:
        // converting maps all of them, and a recording made straight to mp4 is this file.
        return count($this->audioTracks($this->originalPath($recording))) < 2;
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
        $source = $this->originalPath($recording);

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
     * @return list<array{language: ?string, channels: ?string, described: bool}>
     */
    private function audioTracks(string $file): array
    {
        return AudioTracks::of($this->ffmpeg, $file);
    }

    /**
     * The recording as it came off the air, which is what everything here reads.
     *
     * Converting writes a second file and leaves this one alone, so it stays the source
     * both for playback and for counting what the broadcast carried.
     *
     * @param array<string, mixed> $recording
     */
    private function originalPath(array $recording): string
    {
        return rtrim(dirname($this->directory), '/') . '/' . $recording['path'];
    }

    /**
     * Which track to offer first: the one that is not a description of the picture.
     *
     * @param list<array<string, mixed>> $tracks
     */
    private static function defaultTrack(array $tracks): int
    {
        return AudioTracks::preferred($tracks);
    }

    /**
     * @return string[]
     */
    private function ffmpegArguments(string $source, string $directory): array
    {
        $tracks = $this->audioTracks($source);

        // Nothing to choose between, in either sense: keep the single playlist the player
        // already knows rather than a master describing one of everything.
        if (count($tracks) < 2 && count($this->renditions) < 2) {
            return $this->singleTrackArguments($source, $directory);
        }

        [$graph, $outputs, $streams] = HlsLadder::plan($this->renditions, $tracks, self::defaultTrack($tracks));

        return array_merge([
            $this->ffmpeg, '-hide_banner', '-nostdin', '-loglevel', 'error',
            '-fflags', '+genpts+discardcorrupt',
            '-i', $source,
            '-filter_complex', implode(';', $graph),
        ], $outputs, [
            '-fps_mode', 'passthrough',
            '-c:v', 'libx264', '-preset', 'veryfast', '-pix_fmt', 'yuv420p', '-crf', '21',
        ], count($this->renditions) > 1 ? [
            // Key frames where the segments are cut, so a player can change size at any of
            // them. Recordings are cut into four-second segments, not live playback's two,
            // and keyframes landing anywhere else leave the sizes unable to line up.
            '-force_key_frames', sprintf('expr:gte(t,n_forced*%d)', self::SEGMENT_SECONDS),
            '-sc_threshold', '0',
        ] : [], [
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
            // One file per size rather than one per segment, with the playlist pointing at
            // byte ranges inside it. A three-hour recording at four-second segments is
            // 2,700 files for each size it is offered at, and they are working state nobody
            // ever looks at; this makes it one. The player fetches ranges instead of whole
            // files, which is served already -- the route answers 206 with a Content-Range.
            '-hls_flags', 'independent_segments+single_file',
            // Every size, and every language, becomes its own playlist. The audio group is
            // what lets a player offer the languages against any of the sizes.
            '-var_stream_map', implode(' ', $streams),
            '-master_pl_name', 'index.m3u8',
            // No counter in the name: there is one file, not a numbered run of them.
            '-hls_segment_filename', "$directory/v%v.ts",
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
            '-vf', sprintf('estdif=mode=frame:deint=interlaced,scale=w=-2:h=trunc(min(%d\,ih)/2)*2', $this->renditions[0]),
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
            // One file, with the playlist pointing at byte ranges inside it; see the note
            // on the same flag above.
            '-hls_flags', 'independent_segments+single_file',
            '-hls_segment_filename', "$directory/seg.ts",
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
            'audio' => $this->audioTracks($this->originalPath($recording)),
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
     * A copy converted ahead of time: nothing to start, nothing to wait for.
     *
     * @param array<string, mixed> $recording
     * @return array<string, mixed>
     */
    private function describeStored(array $recording, string $directory): array
    {
        // With several sizes index.m3u8 only names the others; the segments are counted in
        // the first size's playlist, as they are for a session.
        $playlist = @file_get_contents("$directory/v0.m3u8");
        $playlist = $playlist === false ? (string) @file_get_contents("$directory/index.m3u8") : $playlist;

        return [
            'kind'        => 'hls',
            'recordingId' => $recording['id'],
            'title'       => $recording['title'],
            'virtual'     => $recording['virtual'],
            'subtitle'    => trim("{$recording['virtual']} {$recording['channelName']}"),
            'playlist'    => "/recordings/{$recording['id']}/hls/index.m3u8",
            'converting'  => false,
            'ready'       => true,
            // Nothing to keep alive and nothing left to report, so the page can stop asking.
            'stored'   => true,
            'segments' => substr_count($playlist, '#EXTINF'),
            'viewers'  => 1,
            // What each track was in the broadcast, so the player can name them.
            'audio' => $this->audioTracks($this->originalPath($recording)),
            'error' => null,
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
