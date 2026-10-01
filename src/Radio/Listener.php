<?php

declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave\Radio;

/**
 * One station being listened to: nrsc5 decoding it, ffmpeg making it playable, and a note
 * kept of everything nrsc5 says along the way.
 *
 * Television needs only ffmpeg, because the tuner hands over a finished stream. Radio needs
 * two programs joined end to end -- nrsc5 writes sound, ffmpeg reads it -- and somebody to
 * read what nrsc5 prints, because that is where the station's name and the song are. A
 * shell could do the joining, but not the reading, and not on Windows. So this is the
 * process a session starts (tools/radio.php), and the two are its children.
 *
 * In the session's directory it leaves:
 *   radio.json   the station as last heard: name, song, signal, pictures
 *   nrsc5.log    what nrsc5 printed, without the once-a-second signal reports
 *   <n>_<name>   pictures the station sent, written by nrsc5 itself
 * beside the playlist and segments ffmpeg writes there.
 */
final class Listener
{
    public const STATE_FILE = 'radio.json';
    public const LOG_FILE   = 'nrsc5.log';

    private const SEGMENT_SECONDS = 2;

    /** nrsc5's reasons for stopping, said the way the page would say them. */
    private const EXPLANATIONS = [
        'Connection failed.'         => 'The rtl_tcp server could not be reached',
        'Open remote device failed.' => 'The rtl_tcp server did not answer like a dongle',
        'Open device failed.'        => 'No RTL-SDR dongle was found, or something else is using it',
        'Lost device'                => 'The dongle stopped answering; it may have been unplugged',
    ];

    private string $directory;
    /** @var string[] */
    private array $receiver;
    /** @var string[] */
    private array $encoder;
    private StationState $state;
    /** @var resource */
    private $report;

    /**
     * @param string[]      $receiver the nrsc5 command, as Receiver::arguments() builds it
     * @param string[]      $encoder  the ffmpeg command, as encoderArguments() builds it
     * @param int           $program  the program being played, so its pictures can be told from the others'
     * @param resource|null $report   where a failure is explained; standard output unless told otherwise
     */
    public function __construct(string $directory, array $receiver, array $encoder, int $program = 0, $report = null)
    {
        $this->directory = rtrim($directory, '/');
        $this->receiver  = $receiver;
        $this->encoder   = $encoder;
        $this->state     = new StationState($program);
        $this->report    = $report ?? STDOUT;
    }

    /**
     * ffmpeg turning nrsc5's raw sound into a playlist a browser can play.
     *
     * The playlist is laid out as live television's is -- a master naming one variant, and
     * that variant's rolling window of segments -- so the same player, the same rewind and
     * the same session bookkeeping serve both. There is one variant because there is
     * nothing to trade: the broadcast is under 100 kbit/s to begin with.
     *
     * @return string[]
     */
    public static function encoderArguments(string $ffmpeg, string $directory, int $rewindSeconds): array
    {
        return [
            $ffmpeg, '-hide_banner', '-nostdin', '-loglevel', 'error',
            // What nrsc5 writes with "-t raw": 16-bit stereo at 44.1 kHz, and no header to
            // say so.
            '-f', 's16le', '-ar', '44100', '-ac', '2',
            '-i', 'pipe:0',
            '-c:a', 'aac', '-b:a', '128k',
            '-f', 'hls', '-hls_time', (string) self::SEGMENT_SECONDS,
            '-hls_list_size', (string) max(10, intdiv($rewindSeconds, self::SEGMENT_SECONDS)),
            '-hls_flags', 'delete_segments+independent_segments+omit_endlist+temp_file',
            '-var_stream_map', 'a:0',
            '-master_pl_name', 'index.m3u8',
            '-hls_segment_filename', "$directory/v%v_%05d.ts",
            "$directory/v%v.m3u8",
        ];
    }

    /**
     * Listen until nrsc5 stops, whether because it was told to or because it could not go on.
     *
     * @return int the exit status: zero only when it was stopped rather than failed
     */
    public function run(): int
    {
        $this->writeState();

        // nrsc5 takes keystrokes from a terminal and nothing from anything else, so it is
        // given nothing. Its sound goes straight to ffmpeg without passing through here.
        $receiver = @proc_open(
            $this->receiver,
            [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );

        if (!is_resource($receiver)) {
            return $this->fail('Unable to start nrsc5');
        }

        $encoder = @proc_open($this->encoder, [0 => $pipes[1], 1 => STDOUT, 2 => STDERR], $unused);

        // ffmpeg holds its own end now. Keeping this one open would mean nrsc5 never
        // noticed ffmpeg going, and wrote into a pipe nobody was reading.
        fclose($pipes[1]);

        if (!is_resource($encoder)) {
            proc_terminate($receiver);
            proc_close($receiver);

            return $this->fail('Unable to start ffmpeg');
        }

        $this->stopChildrenWhenTold($receiver, $encoder);
        $this->follow($pipes[2]);

        fclose($pipes[2]);

        $status = proc_close($receiver);
        proc_close($encoder);

        $error = $this->state->getError();

        if ($error !== null || $status !== 0) {
            return $this->fail($error ?? "nrsc5 stopped unexpectedly (exit status $status)");
        }

        return 0;
    }

    /**
     * Read nrsc5's log to its end, keeping the station file current.
     *
     * @param resource $log
     */
    private function follow($log): void
    {
        $kept    = @fopen("$this->directory/" . self::LOG_FILE, 'ab');
        $pending = '';
        $dirty   = false;
        $written = 0.0;

        stream_set_blocking($log, false);

        while (!feof($log)) {
            $read   = [$log];
            $write  = null;
            $except = null;

            // Woken at least twice a second, so a change that arrived just after the last
            // write is not left unsaid until the station next speaks.
            if (@stream_select($read, $write, $except, 0, 500000) === false) {
                continue;
            }

            $chunk = $read === [] ? '' : (string) fread($log, 65536);
            $lines = explode("\n", $pending . $chunk);

            $pending = (string) array_pop($lines);

            foreach ($lines as $line) {
                $dirty = $this->state->apply($line) || $dirty;

                // Signal reports arrive every second for as long as it plays. They are in
                // the station file, where they are wanted, and would be most of this one.
                if ($kept !== false && !preg_match('/^\S+ (MER|BER|Audio bit rate): /', $line)) {
                    fwrite($kept, $line . "\n");
                }
            }

            if ($dirty && microtime(true) - $written >= 0.5) {
                $this->writeState();
                $dirty   = false;
                $written = microtime(true);
            }
        }

        if ($pending !== '') {
            $this->state->apply($pending);
        }

        if ($kept !== false) {
            fclose($kept);
        }

        $this->writeState();
    }

    /**
     * Written whole and then moved into place: a request reading it mid-write would
     * otherwise find half a station.
     */
    private function writeState(): void
    {
        $file = "$this->directory/" . self::STATE_FILE;
        $json = json_encode(
            $this->state->toArray() + ['updatedAt' => time()],
            JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE
        );

        if ($json !== false && @file_put_contents("$file.tmp", $json) !== false) {
            @rename("$file.tmp", $file);
        }
    }

    /**
     * Say why, as the last line of this process's output.
     *
     * That line is what a session reports when its process has gone, so it has to be the
     * reason and it has to come last: ffmpeg, starved of input, may have had its own say
     * first.
     */
    private function fail(string $reason): int
    {
        fwrite($this->report, (self::EXPLANATIONS[$reason] ?? $reason) . "\n");

        return 1;
    }

    /**
     * Take nrsc5 and ffmpeg along when this process is told to stop.
     *
     * Where a session is its own process group the signal reaches all three at once and
     * this changes nothing. On macOS only this process is signalled, and without this the
     * other two would carry on with nobody left to stop them.
     *
     * @param resource $receiver
     * @param resource $encoder
     */
    private function stopChildrenWhenTold($receiver, $encoder): void
    {
        if (!function_exists('pcntl_signal') || !function_exists('pcntl_async_signals')) {
            return;
        }

        pcntl_async_signals(true);

        $stop = static function () use ($receiver, $encoder): void {
            // ffmpeg first and gently: it closes its playlist properly when asked.
            @proc_terminate($encoder);
            @proc_terminate($receiver);

            exit(0);
        };

        pcntl_signal(SIGTERM, $stop);
        pcntl_signal(SIGINT, $stop);
    }
}
