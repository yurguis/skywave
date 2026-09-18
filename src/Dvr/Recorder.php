<?php

declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave\Dvr;

use RuntimeException;
use Skywave\Hdhomerun\Device;
use Skywave\Hdhomerun\Exception\HdhomerunException;
use Skywave\Hdhomerun\Tuner;
use Throwable;

/**
 * Starts, watches and stops recordings.
 *
 * One ffmpeg process per recording reads the program from the tuner's HTTP stream and
 * writes it to the recordings folder; the tuner is reserved for as long as it runs, so
 * live playback and guide updates leave it alone. Nothing is kept in memory: every tick
 * reads the database, so the recorder can be restarted mid-recording and pick up where
 * it left off.
 */
class Recorder
{
    private const HTTP_STREAM_PORT = 5004;

    /** Keep asking the device for a little more than we record, so it does not stop first. */
    private const SOURCE_MARGIN_SECONDS = 30;

    /** A recording shorter than this never really started. */
    private const MINIMUM_SECONDS = 5;

    /** Give up on a source that sends nothing for this long, rather than waiting forever. */
    private const SOURCE_TIMEOUT_SECONDS = 20;

    /** A file that has not grown for this long is not being recorded, whatever ffmpeg thinks. */
    private const STALL_SECONDS = 120;

    /** Worth restarting only while this much of the program is still to come. */
    private const RETRY_WHEN_SECONDS_LEFT = 60;

    /** A channel that keeps dropping should not fill the drive with fragments. */
    private const MAXIMUM_ATTEMPTS = 3;

    /** Wait before trying a program again, rather than asking the device every tick. */
    private const RETRY_AFTER_SECONDS = 60;

    /** Refuse to start when the drive has less room than this. */
    private const MINIMUM_FREE_BYTES = 2 * 1024 * 1024 * 1024;

    private RecordingStore $store;
    private TunerReservations $reservations;
    private string $directory;
    private string $ffmpeg;
    private int $height;
    /** @var callable(string): void */
    private $log;

    /**
     * @param string $directory where recordings are written
     * @param int $height tallest picture for recordings converted while recording
     * @param callable(string): void|null $log progress lines
     */
    public function __construct(RecordingStore $store, TunerReservations $reservations, string $directory, string $ffmpeg = 'ffmpeg', int $height = 720, ?callable $log = null)
    {
        $this->store        = $store;
        $this->reservations = $reservations;
        $this->directory    = rtrim($directory, '/');
        $this->ffmpeg       = $ffmpeg;
        $this->height       = max(144, min(2160, $height));
        $this->log          = $log ?? static function (string $line): void {
        };
    }

    /**
     * Settings from RECORDINGS_DIR, FFMPEG and RECORDING_HEIGHT.
     *
     * @param callable(string): void|null $log
     */
    public static function fromEnvironment(?callable $log = null): self
    {
        $env = static function (string $name, string $default): string {
            $value = getenv($name);

            return $value === false || $value === '' ? $default : $value;
        };

        return new self(
            RecordingStore::fromEnvironment(),
            TunerReservations::fromEnvironment(),
            $env('RECORDINGS_DIR', dirname(__DIR__, 2) . '/data/recordings'),
            $env('FFMPEG', 'ffmpeg'),
            (int) $env('RECORDING_HEIGHT', '720'),
            $log
        );
    }

    public function getDirectory(): string
    {
        return $this->directory;
    }

    /**
     * Full path of a recording's file, or null when it is no longer there.
     *
     * @param array<string, mixed> $recording
     */
    public function fileFor(array $recording): ?string
    {
        $path = "$this->directory/{$recording['path']}";

        return is_file($path) ? $path : null;
    }

    /**
     * Every file a recording owns: the broadcast, any browser-ready copy, and the logs and
     * half-finished copies left beside them.
     *
     * @param array<string, mixed> $recording
     * @return string[]
     */
    public function filesFor(array $recording): array
    {
        $files = [];

        foreach (array_filter([$recording['path'] ?? null, $recording['convertedPath'] ?? null]) as $path) {
            $captions = self::captionsPath("$this->directory/$path");

            if (is_file($captions)) {
                $files[] = $captions;
            }

            foreach (['', '.log', '.part', '.part.log'] as $suffix) {
                $file = "$this->directory/$path$suffix";

                if (is_file($file)) {
                    $files[] = $file;
                }
            }
        }

        return $files;
    }

    /**
     * One pass: finish what has ended, start what is due, and give up on what was missed.
     */
    public function tick(): void
    {
        $now = time();

        foreach ($this->store->getRecordings(null, RecordingStore::STATUS_RECORDING) as $recording) {
            $this->follow($recording, $now);
        }

        foreach ($this->store->getDueSchedules($now) as $schedule) {
            $this->begin($schedule, $now);
        }

        foreach ($this->store->getMissedSchedules($now) as $schedule) {
            $this->store->markSchedule($schedule['id'], RecordingStore::STATUS_MISSED, 'The recorder was not running while this program aired');
            ($this->log)("Missed {$schedule['title']} on {$schedule['virtual']}");
        }

        foreach ($this->store->getConvertingRecordings() as $recording) {
            $this->followConversion($recording);
        }

        foreach ($this->store->getRecordingsToConvert() as $recording) {
            $this->startConversion($recording);
        }
    }

    /**
     * Stop a recording early, keeping what has been written so far.
     */
    public function stop(int $id, string $status = RecordingStore::STATUS_DONE): bool
    {
        $recording = $this->store->getRecording($id);

        if ($recording === null || $recording['status'] !== RecordingStore::STATUS_RECORDING) {
            return false;
        }

        $this->endProcess($recording);
        $this->finish($recording, $status, null);

        return true;
    }

    /**
     * Watch a running recording: stop it at its end time, or write down how it ended.
     *
     * @param array<string, mixed> $recording
     */
    private function follow(array $recording, int $now): void
    {
        $file  = "$this->directory/{$recording['path']}";
        $bytes = is_file($file) ? (int) filesize($file) : 0;

        // Somebody asked for it to stop, e.g. from the page, which cannot signal ffmpeg
        // itself: the process belongs to this container.
        if ($recording['stopRequested'] !== null) {
            $this->endProcess($recording);
            $this->finish($recording, (string) $recording['stopRequested'], null);

            return;
        }

        if ($now >= $recording['stopsAt']) {
            $this->endProcess($recording);
            $this->finish($recording, RecordingStore::STATUS_DONE, null);

            return;
        }

        if (!DetachedProcess::isRunning((int) $recording['pid'])) {
            $recorded = $now - $recording['startedAt'];
            $error    = DetachedProcess::lastLogLine("$file.log") ?? 'The recorder stopped early';

            $this->finish(
                $recording,
                $bytes > 0 && $recorded >= self::MINIMUM_SECONDS ? RecordingStore::STATUS_DONE : RecordingStore::STATUS_FAILED,
                $bytes > 0 && $recorded >= self::MINIMUM_SECONDS ? null : $error
            );
            $this->restartIfTimeRemains($recording);

            return;
        }

        // Only write the size when it changes, so updatedAt says when the file last grew.
        if ($bytes > (int) $recording['bytes']) {
            $this->store->updateRecording($recording['id'], ['bytes' => $bytes]);
        } elseif ($now - (int) $recording['updatedAt'] >= self::STALL_SECONDS) {
            // A tuner can stop sending while ffmpeg still holds an open connection: it
            // waits on data that never comes, and the recording looks healthy from here.
            $this->endProcess($recording);
            $this->finish($recording, RecordingStore::STATUS_FAILED, 'The tuner stopped sending');
            $this->restartIfTimeRemains($recording);

            return;
        }

        if ($recording['reservation'] !== null) {
            $this->reservations->renew((string) $recording['reservation']);
        }
    }

    /**
     * Put a schedule back when its recording ended by itself before the program did, so a
     * glitch costs a gap rather than the rest of the program.
     *
     * An ending somebody asked for is not a glitch: stopping a recording has to mean it
     * stays stopped.
     *
     * @param array<string, mixed> $recording
     */
    private function restartIfTimeRemains(array $recording): void
    {
        if ($recording['scheduleId'] === null || $recording['stopRequested'] !== null) {
            return;
        }

        $scheduleId = (int) $recording['scheduleId'];
        $remaining  = (int) $recording['stopsAt'] - time();

        if ($remaining < self::RETRY_WHEN_SECONDS_LEFT) {
            return;
        }

        // Count what has been tried, not what was captured: a program that cannot even be
        // started leaves no recording behind, and used to be retried without end.
        $schedule = $this->store->getSchedule($scheduleId);
        $attempts = ($schedule['attempts'] ?? 0) + 1;

        if ($attempts >= self::MAXIMUM_ATTEMPTS) {
            $this->store->markSchedule($scheduleId, RecordingStore::STATUS_FAILED, sprintf(
                'Recording kept stopping; gave up after %d tries',
                $attempts
            ));
            ($this->log)("Gave up on {$recording['title']} after $attempts tries");

            return;
        }

        // Due again in a moment rather than instantly, so a device having trouble is not
        // asked ten times a minute.
        $this->store->retrySchedule($scheduleId, $attempts, time() + self::RETRY_AFTER_SECONDS);
        ($this->log)(sprintf(
            'Restarting %s in %d seconds, %d minutes still to come',
            $recording['title'],
            self::RETRY_AFTER_SECONDS,
            intdiv($remaining, 60)
        ));
    }

    /**
     * @param array<string, mixed> $schedule
     */
    private function begin(array $schedule, int $now): void
    {
        foreach ($this->store->getRecordings(null, RecordingStore::STATUS_RECORDING) as $running) {
            if ($running['scheduleId'] === $schedule['id']) {
                return;
            }
        }

        try {
            $this->guardDirectory();

            $device          = Device::at($schedule['device']);
            [$tuner, $token] = $this->reserveTuner($device, $schedule);

            try {
                $this->startRecording($schedule, $tuner, $token, $now);
            } catch (Throwable $e) {
                $this->reservations->release($token);

                throw $e;
            }
        } catch (HdhomerunException | RuntimeException $e) {
            // A program that could not be started is worth another try while it is still
            // on, but only a few, and not every ten seconds.
            $attempts  = (int) ($schedule['attempts'] ?? 0) + 1;
            $remaining = $schedule['start'] + $schedule['duration'] + $schedule['padEnd'] - $now;

            if ($attempts < self::MAXIMUM_ATTEMPTS && $remaining >= self::RETRY_WHEN_SECONDS_LEFT) {
                $this->store->retrySchedule($schedule['id'], $attempts, $now + self::RETRY_AFTER_SECONDS);
                ($this->log)("Could not record {$schedule['title']} ({$e->getMessage()}); trying again in " . self::RETRY_AFTER_SECONDS . ' seconds');

                return;
            }

            $this->store->markSchedule($schedule['id'], RecordingStore::STATUS_FAILED, $e->getMessage());
            ($this->log)("Failed to record {$schedule['title']}: {$e->getMessage()}");
        }
    }

    /**
     * Tune a reserved tuner and start ffmpeg on it.
     *
     * @param array<string, mixed> $schedule
     */
    private function startRecording(array $schedule, Tuner $tuner, string $token, int $now): void
    {
        $channel = "auto:{$schedule['physical']}";
        $tuner->setChannel($channel);

        if (!$tuner->waitForLock()->isLockSupported()) {
            throw new RuntimeException("No signal on channel {$schedule['physical']}");
        }

        $stopsAt = $schedule['start'] + $schedule['duration'] + $schedule['padEnd'];
        $seconds = max(self::MINIMUM_SECONDS, $stopsAt - $now);
        $path    = $this->freeFileName($schedule);
        $file    = "$this->directory/$path";

        $source = sprintf(
            'http://%s:%d/tuner%d/ch%d?duration=%d',
            $schedule['device'],
            self::HTTP_STREAM_PORT,
            $tuner->getIndex(),
            $schedule['physical'],
            $seconds + self::SOURCE_MARGIN_SECONDS
        );

        $pid = DetachedProcess::start(
            $this->ffmpegArguments($source, (int) $schedule['program'], $seconds, $schedule['format'], $file),
            "$file.log"
        );

        $this->store->addRecording([
            'scheduleId'  => $schedule['id'],
            'device'      => $schedule['device'],
            'physical'    => $schedule['physical'],
            'program'     => $schedule['program'],
            'virtual'     => $schedule['virtual'],
            'channelName' => $schedule['channelName'],
            'title'       => $schedule['title'],
            'description' => $schedule['description'],
            'path'        => $path,
            'format'      => $schedule['format'],
            'tuner'       => $tuner->getIndex(),
            'pid'         => $pid,
            'startedAt'   => $now,
            'stopsAt'     => $stopsAt,
            'reservation' => $token,
        ]);
        $this->store->markSchedule($schedule['id'], RecordingStore::STATUS_RECORDING);

        ($this->log)(sprintf(
            'Recording %s on %s (tuner %d) until %s',
            $schedule['title'],
            $schedule['virtual'],
            $tuner->getIndex(),
            date('H:i', $stopsAt)
        ));
    }

    /**
     * An idle tuner nobody else has claimed, reserved for this recording.
     *
     * Says why each tuner was passed over when none can be had: a recording that does not
     * happen should never leave "every tuner is busy" as the only clue.
     *
     * @param array<string, mixed> $schedule
     * @return array{0: Tuner, 1: string} the tuner and its reservation token
     */
    private function reserveTuner(Device $device, array $schedule): array
    {
        $reasons = [];

        foreach ($device->getTuners() as $tuner) {
            $index = $tuner->getIndex();
            $held  = $this->reservations->find($schedule['device'], $index);

            if ($held !== null) {
                $reasons[] = "tuner $index: {$held['label']}";

                continue;
            }

            try {
                // A tuner keeps its last channel long after everyone has finished with it,
                // and a recording retunes it anyway. What makes it unavailable is somebody
                // streaming from it or holding its lock.
                $target = $tuner->getTarget();
                $owner  = $tuner->getLockOwner();

                if ($target !== 'none') {
                    $reasons[] = "tuner $index: streaming to $target";

                    continue;
                }

                if (!in_array($owner, [null, 'none'], true)) {
                    $reasons[] = "tuner $index: locked by $owner";

                    continue;
                }
            } catch (HdhomerunException $e) {
                $reasons[] = "tuner $index: {$e->getMessage()}";

                continue;
            }

            $token = $this->reservations->reserve($schedule['device'], $index, "Recording {$schedule['title']}");

            if ($token !== null) {
                return [$tuner, $token];
            }

            $reasons[] = "tuner $index: claimed by something else just now";
        }

        throw new RuntimeException($reasons === []
            ? 'The device reported no tuners'
            : 'No tuner available (' . implode('; ', $reasons) . ')');
    }

    /**
     * @param array<string, mixed> $recording
     */
    private function endProcess(array $recording): void
    {
        DetachedProcess::stop((int) $recording['pid']);
    }

    /**
     * Make the browser-ready copy of a "both" recording, now that the broadcast is safely
     * on disk. It is written under a temporary name: an interrupted conversion must not
     * look like a finished one.
     *
     * @param array<string, mixed> $recording
     */
    private function startConversion(array $recording): void
    {
        $source = "$this->directory/{$recording['path']}";

        if (!is_file($source)) {
            $this->store->updateRecording($recording['id'], ['convertError' => 'The recording is no longer on disk']);

            return;
        }

        try {
            $this->guardDirectory();
            $partial = "$this->directory/" . self::convertedPath($recording) . '.part';
            // Asked for by hand, the page says what to do with the picture: null keeps
            // whatever was broadcast. "both" still follows RECORDING_HEIGHT, which is a
            // choice made once for every recording rather than one at a time.
            $height = ($recording['convertRequested'] ?? false)
                ? ($recording['convertHeight'] ?? null)
                : $this->height;
            $pid = DetachedProcess::start($this->conversionArguments($source, $partial, $height), "$partial.log");
        } catch (RuntimeException $e) {
            $this->store->updateRecording($recording['id'], ['convertError' => $e->getMessage()]);
            ($this->log)("Cannot convert {$recording['title']}: {$e->getMessage()}");

            return;
        }

        $this->store->updateRecording($recording['id'], ['convertPid' => $pid]);
        ($this->log)("Converting {$recording['title']} for browsers");
    }

    /**
     * @param array<string, mixed> $recording
     */
    private function followConversion(array $recording): void
    {
        if (DetachedProcess::isRunning((int) $recording['convertPid'])) {
            return;
        }

        $path    = self::convertedPath($recording);
        $partial = "$this->directory/$path.part";
        $bytes   = is_file($partial) ? (int) filesize($partial) : 0;

        // ffmpeg writes the index last, so a file it never finished cannot be read back.
        if ($bytes < 1 || !$this->isPlayable($partial)) {
            @unlink($partial);
            $this->store->updateRecording($recording['id'], [
                'convertPid'   => null,
                'convertError' => DetachedProcess::lastLogLine("$partial.log") ?? 'The conversion did not finish',
            ]);
            ($this->log)("Could not convert {$recording['title']}");

            return;
        }

        rename($partial, "$this->directory/$path");
        @unlink("$partial.log");

        // The captions were written next to the half-finished file; move them with it.
        $captions = self::captionsPath($partial);

        if (is_file($captions) && filesize($captions) > 0) {
            rename($captions, self::captionsPath("$this->directory/$path"));
        } else {
            @unlink($captions);
        }

        $this->store->updateRecording($recording['id'], [
            'convertPid'     => null,
            'convertedPath'  => $path,
            'convertedBytes' => $bytes,
        ]);
        ($this->log)(sprintf('Converted %s (%.1f GB)', $recording['title'], $bytes / 1e9));
    }

    /**
     * @return string[]
     */
    /**
     * @param int|null $height picture height to convert to, or null to keep the source's
     */
    private function conversionArguments(string $source, string $file, ?int $height = null): array
    {
        // Deinterlacing a frame at a time keeps the broadcast's timing, and with it the
        // captions. The scale only rounds the picture to even numbers, which yuv420p needs.
        $scale = $height === null
            ? 'scale=w=trunc(iw/2)*2:h=trunc(ih/2)*2'
            : sprintf('scale=w=-2:h=trunc(min(%d\,ih)/2)*2', $height);

        // A second reading of the same file, with the broadcast's captions exposed as a
        // subtitle stream. They survive into the mp4's video as they always did, but a
        // browser will not show captions carried inside a plain file the way it does in a
        // playlist, so they are written out beside it as well.
        //
        // No escaping: sanitize() allows only letters, digits, space, dot, dash and
        // underscore into a name, none of which mean anything to a filtergraph.
        $captions = 'movie=' . $source . '[out0+subcc]';

        return [
            $this->ffmpeg, '-hide_banner', '-nostdin', '-loglevel', 'error',
            '-fflags', '+genpts+discardcorrupt',
            '-i', $source,
            '-f', 'lavfi', '-i', $captions,
            // Every audio track, not just the first: this broadcast carries Spanish,
            // Portuguese and English 5.1, and mapping one silently threw two away.
            '-map', '0:v:0', '-map', '0:a',
            '-vf', 'estdif=mode=frame:deint=interlaced,' . $scale,
            '-fps_mode', 'passthrough',
            '-c:v', 'libx264', '-preset', 'veryfast', '-pix_fmt', 'yuv420p', '-crf', '21',
            // No downmix: a 5.1 track stays 5.1, whatever language it is in. Browsers
            // play multi-channel AAC from an mp4, and a copy kept for the long term
            // should not quietly lose the surround the broadcast sent.
            '-c:a', 'aac',
            // Put the index at the front so a browser can start without the whole file.
            '-movflags', '+faststart',
            '-f', 'mp4',
            '-y', $file,
            // Optional: a programme with no captions must still convert.
            '-map', '1:s:0?',
            '-f', 'webvtt',
            '-y', self::captionsPath($file),
        ];
    }

    /**
     * Where a converted recording's captions live: beside it, same name, .vtt.
     */
    public static function captionsPath(string $file): string
    {
        return preg_replace('/\.mp4(\.part)?$/', '', $file) . '.vtt';
    }

    private function isPlayable(string $file): bool
    {
        $probe = str_replace('ffmpeg', 'ffprobe', $this->ffmpeg);
        $shown = (string) shell_exec(sprintf(
            '%s -v error -show_entries format=duration -of csv=p=0 %s 2>/dev/null',
            escapeshellarg($probe),
            escapeshellarg($file)
        ));

        return (float) trim($shown) > 0;
    }

    /**
     * @param array<string, mixed> $recording
     */
    private static function convertedPath(array $recording): string
    {
        return preg_replace('/\.ts$/i', '', (string) $recording['path']) . '.mp4';
    }

    /**
     * @param array<string, mixed> $recording
     */
    private function finish(array $recording, string $status, ?string $error): void
    {
        $file  = "$this->directory/{$recording['path']}";
        $bytes = is_file($file) ? (int) filesize($file) : 0;

        $this->store->updateRecording($recording['id'], [
            'status'  => $status,
            'endedAt' => time(),
            'bytes'   => $bytes,
            'error'   => $error,
            // The request has been carried out; leaving it set would read as "stopping".
            'stopRequested' => null,
        ]);

        if ($recording['scheduleId'] !== null) {
            $this->store->markSchedule((int) $recording['scheduleId'], $status, $error);
        }

        if ($recording['reservation'] !== null) {
            $this->reservations->release((string) $recording['reservation']);
        } else {
            $this->reservations->releaseTuner($recording['device'], (int) $recording['tuner']);
        }

        ($this->log)(sprintf(
            '%s %s (%.1f GB)%s',
            $status === RecordingStore::STATUS_DONE ? 'Recorded' : ucfirst($status),
            $recording['title'],
            $bytes / 1e9,
            $error === null ? '' : ": $error"
        ));
    }

    /**
     * @return string[]
     */
    private function ffmpegArguments(string $source, int $program, int $seconds, string $format, string $file): array
    {
        $arguments = [
            $this->ffmpeg, '-hide_banner', '-nostdin', '-loglevel', 'error',
            '-fflags', '+genpts+discardcorrupt',
            // A tuner that stops sending leaves the connection open, and ffmpeg would wait
            // on it for the rest of the program. Give up instead, so the recorder notices.
            '-rw_timeout', (string) (self::SOURCE_TIMEOUT_SECONDS * 1000000),
            '-i', $source,
        ];

        if ($format === 'mp4') {
            // One picture size, deinterlaced a frame at a time so the closed captions that
            // travel with each frame survive, as in live playback.
            return array_merge($arguments, [
                '-map', "0:p:$program:v:0", '-map', "0:p:$program:a:0",
                '-vf', sprintf('estdif=mode=frame:deint=interlaced,scale=w=-2:h=trunc(min(%d\,ih)/2)*2', $this->height),
                '-fps_mode', 'passthrough',
                '-c:v', 'libx264', '-preset', 'veryfast', '-pix_fmt', 'yuv420p', '-crf', '21',
                // Whatever layout each track was broadcast with, in any language: a
                // recording kept on disk should not lose surround the air carried.
                '-c:a', 'aac',
                '-movflags', '+faststart',
                '-t', (string) $seconds,
                '-y', $file,
            ]);
        }

        // The broadcast as it was sent: no transcoding, so no CPU cost and nothing lost.
        return array_merge($arguments, [
            '-map', "0:p:$program",
            '-c', 'copy',
            '-f', 'mpegts',
            '-t', (string) $seconds,
            '-y', $file,
        ]);
    }

    /**
     * A name nothing has taken. A restarted recording is another attempt at the same
     * program, and must not overwrite what the first attempt managed to capture.
     *
     * @param array<string, mixed> $schedule
     */
    private function freeFileName(array $schedule): string
    {
        $name      = $this->fileName($schedule);
        $extension = $schedule['format'] === 'mp4' ? '.mp4' : '.ts';
        $base      = substr($name, 0, -strlen($extension));

        for ($attempt = 2; is_file("$this->directory/$name"); $attempt++) {
            $name = "$base ($attempt)$extension";
        }

        return $name;
    }

    /**
     * @param array<string, mixed> $schedule
     */
    private function fileName(array $schedule): string
    {
        $name = sprintf(
            '%s - %s - %s',
            date('Y-m-d H-i', $schedule['start']),
            $schedule['virtual'],
            $schedule['title']
        );

        return self::sanitize($name) . ($schedule['format'] === 'mp4' ? '.mp4' : '.ts');
    }

    /**
     * The folder has to be there and writable: an external drive that is asleep or
     * unplugged should fail the recording with a clear reason, not a broken file.
     */
    private function guardDirectory(): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new RuntimeException("The recordings folder is not available: $this->directory");
        }

        if (!is_writable($this->directory)) {
            throw new RuntimeException("The recordings folder cannot be written to: $this->directory");
        }

        $free = @disk_free_space($this->directory);

        if ($free !== false && $free < self::MINIMUM_FREE_BYTES) {
            throw new RuntimeException(sprintf('Only %.1f GB left in %s', $free / 1e9, $this->directory));
        }
    }

    private static function sanitize(string $name): string
    {
        $safe = preg_replace('/[^A-Za-z0-9 ._-]+/', ' ', $name) ?? $name;
        $safe = trim((string) preg_replace('/\s+/', ' ', $safe));

        return substr($safe === '' ? 'recording' : $safe, 0, 120);
    }
}
