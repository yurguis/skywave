<?php

declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave\Radio;

/**
 * One station being listened to: something decoding it, ffmpeg making it playable, and a
 * note kept of everything said about it along the way.
 *
 * Television needs only ffmpeg, because the tuner hands over a finished stream. Radio needs
 * programs joined end to end and somebody to read what they print, because that is where
 * the station's name and the song are. A shell could do the joining, but not the reading,
 * and not on Windows. So this is the process a session starts (tools/radio.php), and the
 * rest are its children.
 *
 * What those are depends on the mode:
 *
 *   hd   nrsc5 writes sound and prints the station on its standard error
 *   am   rtlanalog writes sound; nothing describes the station, because AM carries nothing
 *   fm   rtlanalog writes the multiplex twice over, once to ffmpeg for sound and once to
 *        redsea, which reads RDS off it and prints the station as JSON
 *
 * The second copy is rtlanalog's own doing rather than anything arranged here: a tee in
 * this process would put PHP between the dongle and ffmpeg, where a pause is a gap in the
 * sound. It writes the copy without blocking and drops it when redsea is not keeping up,
 * which costs an RDS group and never costs a moment of audio.
 *
 * In the session's directory it leaves:
 *   radio.json   the station as last heard: name, song, signal, pictures
 *   nrsc5.log    what the decoder printed, without the once-a-second signal reports
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
    /** @var string[] the redsea command, or empty when nothing will describe the station */
    private array $metadata;
    private string $mode;
    private StationState $state;
    /** @var resource */
    private $report;

    /**
     * @param string[]      $receiver the decoder command: nrsc5 for HD Radio, rtlanalog for analog
     * @param string[]      $encoder  the ffmpeg command, as encoderArguments() builds it
     * @param int           $program  the program being played, so its pictures can be told from the others'
     * @param resource|null $report   where a failure is explained; standard output unless told otherwise
     * @param string[]      $metadata the redsea command for analog FM, or nothing
     */
    public function __construct(string $directory, array $receiver, array $encoder, int $program = 0, $report = null, string $mode = Receiver::MODE_HD, array $metadata = [])
    {
        $this->directory = rtrim($directory, '/');
        $this->receiver  = $receiver;
        $this->encoder   = $encoder;
        $this->mode      = Receiver::validateMode($mode);
        $this->metadata  = $metadata;
        $this->state     = new StationState($program, $this->mode);
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
    public static function encoderArguments(string $ffmpeg, string $directory, int $rewindSeconds, string $mode = Receiver::MODE_HD): array
    {
        return array_merge(
            [$ffmpeg, '-hide_banner', '-nostdin', '-loglevel', 'error'],
            self::inputArguments($mode),
            ['-c:a', 'aac', '-b:a', '128k',
             '-f', 'hls', '-hls_time', (string) self::SEGMENT_SECONDS,
             '-hls_list_size', (string) max(10, intdiv($rewindSeconds, self::SEGMENT_SECONDS)),
             '-hls_flags', 'delete_segments+independent_segments+omit_endlist+temp_file',
             '-var_stream_map', 'a:0',
             '-master_pl_name', 'index.m3u8',
             '-hls_segment_filename', "$directory/v%v_%05d.ts",
             "$directory/v%v.m3u8"]
        );
    }

    /**
     * What arrives on ffmpeg's input, which is not the same thing in each mode.
     *
     * HD Radio arrives as finished sound. AM arrives as finished sound too, narrow the way
     * AM is, and is only kept from hissing above where a station stops.
     *
     * FM arrives as the multiplex and is not sound yet: it is everything the station
     * transmits, up past the pilot at 19 kHz to the RDS subcarrier at 57. Audio is the part
     * below 15 kHz, and it was transmitted with its treble lifted so that hiss would be
     * quieter after the receiver put it back -- which is what the de-emphasis does. Leave
     * either out and the station plays, bright and whistling.
     *
     * @return string[]
     */
    private static function inputArguments(string $mode): array
    {
        if ($mode === Receiver::MODE_AM) {
            return [
                '-f', 's16le', '-ar', (string) Receiver::AM_RATE, '-ac', '1', '-i', 'pipe:0',
                '-af', 'highpass=f=80,lowpass=f=4800',
            ];
        }

        if ($mode === Receiver::MODE_FM) {
            return [
                '-f', 's16le', '-ar', (string) Receiver::MPX_RATE, '-ac', '1', '-i', 'pipe:0',
                '-af', 'lowpass=f=15000,aemphasis=type=75fm:mode=reproduction',
            ];
        }

        // What nrsc5 writes with "-t raw": 16-bit stereo at 44.1 kHz, and no header to
        // say so.
        return ['-f', 's16le', '-ar', '44100', '-ac', '2', '-i', 'pipe:0'];
    }

    /**
     * One of nrsc5's reasons for stopping, said the way the page would say it.
     */
    public static function explain(string $reason): string
    {
        return self::EXPLANATIONS[$reason] ?? $reason;
    }

    /**
     * Listen until nrsc5 stops, whether because it was told to or because it could not go on.
     *
     * @return int the exit status: zero only when it was stopped rather than failed
     */
    public function run(): int
    {
        $this->writeState();

        // Neither decoder takes anything but keystrokes from a terminal, so both are given
        // nothing. Their sound goes straight to ffmpeg without passing through here.
        $wanted = [
            0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        // Analog FM asks rtlanalog for a second copy of the multiplex, which arrives here
        // as a pipe and is handed to redsea without being read on the way.
        if ($this->metadata !== []) {
            $wanted[3] = ['pipe', 'w'];
        }

        $receiver = @proc_open($this->receiver, $wanted, $pipes);

        if (!is_resource($receiver)) {
            return $this->fail('Unable to start ' . $this->decoderName());
        }

        $encoder = @proc_open($this->encoder, [0 => $pipes[1], 1 => STDOUT, 2 => STDERR], $unused);

        // ffmpeg holds its own end now. Keeping this one open would mean the decoder never
        // noticed ffmpeg going, and wrote into a pipe nobody was reading.
        fclose($pipes[1]);

        if (!is_resource($encoder)) {
            proc_terminate($receiver);
            proc_close($receiver);

            return $this->fail('Unable to start ffmpeg');
        }

        $sources = [];
        $reader  = null;

        if (isset($pipes[3])) {
            $reader = @proc_open(
                $this->metadata,
                [0 => $pipes[3], 1 => ['pipe', 'w'], 2 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w']],
                $readerPipes
            );

            fclose($pipes[3]);

            // Losing redsea costs the station's name and nothing else, so it is not worth
            // stopping the music for: the failure is noted and the sound carries on.
            if (is_resource($reader)) {
                $sources['rds'] = $readerPipes[1];
            } else {
                $reader = null;
                fwrite($this->report, "redsea could not be started; the station will play without its name\n");
            }
        }

        // nrsc5 says what it knows on its standard error. rtlanalog says only what went
        // wrong there, which is still worth having when it is why the station stopped.
        $sources[$this->mode === Receiver::MODE_HD ? 'nrsc5' : 'errors'] = $pipes[2];

        $this->stopChildrenWhenTold($receiver, $encoder, $reader);
        $this->follow($sources);

        foreach ($sources as $stream) {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $status = proc_close($receiver);
        proc_close($encoder);

        if ($reader !== null) {
            proc_close($reader);
        }

        $error = $this->state->getError();

        if ($error !== null || $status !== 0) {
            return $this->fail($error ?? $this->decoderName() . " stopped unexpectedly (exit status $status)");
        }

        return 0;
    }

    private function decoderName(): string
    {
        return $this->mode === Receiver::MODE_HD ? 'nrsc5' : 'rtlanalog';
    }

    /**
     * Read what the children print until they stop, keeping the station file current.
     *
     * More than one of them may be talking: on analog FM redsea prints the station while
     * rtlanalog prints only its troubles, and both have to be heard without either being
     * allowed to block the other. A stream that ends is dropped and the rest carry on.
     *
     * @param array<string, resource> $sources by what they are: nrsc5, rds or errors
     */
    private function follow(array $sources): void
    {
        $kept    = @fopen("$this->directory/" . self::LOG_FILE, 'ab');
        $pending = array_fill_keys(array_keys($sources), '');
        $dirty   = false;
        $written = 0.0;

        foreach ($sources as $stream) {
            stream_set_blocking($stream, false);
        }

        while ($sources !== []) {
            $read   = array_values($sources);
            $write  = null;
            $except = null;

            // Woken at least twice a second, so a change that arrived just after the last
            // write is not left unsaid until the station next speaks.
            if (@stream_select($read, $write, $except, 0, 500000) === false) {
                continue;
            }

            foreach ($sources as $kind => $stream) {
                if ($read !== [] && !in_array($stream, $read, true)) {
                    continue;
                }

                $chunk = (string) fread($stream, 65536);
                $lines = explode("\n", $pending[$kind] . $chunk);

                $pending[$kind] = (string) array_pop($lines);

                foreach ($lines as $line) {
                    $dirty = $this->applyLine($kind, $line, $kept) || $dirty;
                }

                if (feof($stream)) {
                    if ($pending[$kind] !== '') {
                        $this->applyLine($kind, $pending[$kind], $kept);
                        $pending[$kind] = '';
                    }

                    unset($sources[$kind]);
                }
            }

            if ($dirty && microtime(true) - $written >= 0.5) {
                $this->writeState();
                $dirty   = false;
                $written = microtime(true);
            }
        }

        if ($kept !== false) {
            fclose($kept);
        }

        $this->writeState();
    }

    /**
     * One line from one of them, understood according to who said it.
     *
     * @param resource|false $kept the log, or false when it could not be opened
     */
    private function applyLine(string $kind, string $line, $kept): bool
    {
        if ($kind === 'rds') {
            $changed = $this->state->applyRds($line);
        } elseif ($kind === 'nrsc5') {
            $changed = $this->state->apply($line);
        } else {
            $changed = $this->state->applyDecoderError($line);
        }

        // Signal reports arrive every second for as long as it plays. They are in the
        // station file, where they are wanted, and would be most of this one.
        if ($kept !== false && $line !== '' && !preg_match('/^\S+ (MER|BER|Audio bit rate): /', $line)) {
            fwrite($kept, $line . "\n");
        }

        return $changed;
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
        fwrite($this->report, self::explain($reason) . "\n");

        return 1;
    }

    /**
     * Take nrsc5 and ffmpeg along when this process is told to stop.
     *
     * Where a session is its own process group the signal reaches all three at once and
     * this changes nothing. On macOS only this process is signalled, and without this the
     * other two would carry on with nobody left to stop them.
     *
     * @param resource      $receiver
     * @param resource      $encoder
     * @param resource|null $reader   redsea, on analog FM
     */
    private function stopChildrenWhenTold($receiver, $encoder, $reader = null): void
    {
        if (!function_exists('pcntl_signal') || !function_exists('pcntl_async_signals')) {
            return;
        }

        pcntl_async_signals(true);

        $stop = static function () use ($receiver, $encoder, $reader): void {
            // ffmpeg first and gently: it closes its playlist properly when asked.
            @proc_terminate($encoder);
            @proc_terminate($receiver);

            if ($reader !== null) {
                @proc_terminate($reader);
            }

            exit(0);
        };

        pcntl_signal(SIGTERM, $stop);
        pcntl_signal(SIGINT, $stop);
    }
}
