<?php

declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

/**
 * Simulated RTL-SDR dongle for developing HD Radio without hardware.
 *
 * Speaks rtl_tcp, the protocol a real dongle is shared over a network with, so nrsc5 and
 * this project's client talk to it exactly as they would to the real thing:
 * - twelve bytes on connecting: "RTL0", the tuner chip and its number of gain steps
 * - five-byte commands from the client (frequency, sample rate, gain and so on)
 * - raw samples to the client without end, at the rate it asked for
 *
 * With --capture, a recording of raw I/Q samples becomes the only station on the dial: it
 * is heard on --capture-frequency and replayed in a loop at the speed it was recorded.
 * Every other frequency, and every frequency without --capture, is noise, which is what an
 * empty channel sounds like to a dongle and leaves nrsc5 searching for a station.
 *
 * A capture is what "nrsc5 -w FILE" or "rtl_sdr -s 1488375" writes: unsigned 8-bit I and Q,
 * interleaved, at 1,488,375 samples a second. The nrsc5 source ships one (support/sample.xz;
 * unpack it first).
 *
 * Like rtl_tcp, it serves one client at a time. A second one waits, unanswered, until the
 * first has gone.
 *
 * Limits: gain, frequency correction and the rest are accepted and ignored, the station is
 * exactly as strong as it was recorded, and it loses a moment each time the recording loops.
 *
 * Usage:
 *   php tools/fake-rtl-tcp.php [--bind=127.0.0.1] [--port=1234] [--verbose]
 *                              [--capture=FILE.cu8 [--capture-frequency=90.5]]
 */

use Skywave\Radio\RtlTcp;

require_once __DIR__ . '/../vendor/autoload.php';

/** What rtl_tcp itself starts at, until the client asks for something else. */
const DEFAULT_SAMPLE_RATE = 2048000;

/** How far off a station's frequency still counts as tuned to it, in Hz. */
const TUNING_TOLERANCE = 10000;

/** Gain steps of the R820T, the tuner in nearly every dongle sold. */
const GAIN_STEPS = 29;

$options = getopt('', ['bind:', 'port:', 'verbose', 'capture:', 'capture-frequency:']);
$bind    = $options['bind'] ?? '127.0.0.1';
$port    = (int) ($options['port'] ?? RtlTcp::PORT);
$verbose = isset($options['verbose']);
$capture = null;

if (isset($options['capture'])) {
    try {
        $capture = IqCapture::load($options['capture'], (float) ($options['capture-frequency'] ?? 90.5));
    } catch (RuntimeException $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
}

$server = @stream_socket_server("tcp://$bind:$port", $errno, $errstr);

if ($server === false) {
    fwrite(STDERR, "Unable to listen on $bind:$port: $errstr\n");
    exit(1);
}

printf("Fake RTL-SDR (%s) listening on %s:%d\n", RtlTcp::tunerName(RtlTcp::TUNER_R820T), $bind, $port);

if ($capture !== null) {
    printf(
        "%.1f MHz carries %s (%.1f seconds, looping)\n",
        $capture->getFrequency() / 1e6,
        basename($capture->getPath()),
        $capture->getSeconds()
    );
}

$noise = noise(1 << 16);

/** @var array<string, mixed>|null $client the one listener being served */
$client = null;

while (true) {
    // Not listening for a second client while one is connected is what makes it wait, as
    // it would on a real dongle.
    $read   = $client === null ? [$server] : [$client['socket']];
    $write  = $client !== null && $client['pending'] !== '' ? [$client['socket']] : [];
    $except = null;

    // While serving, wake up regularly to release the next slice of samples on time.
    if (@stream_select($read, $write, $except, $client === null ? null : 0, $client === null ? null : 5000) === false) {
        break;
    }

    if ($client === null) {
        if (($connection = @stream_socket_accept($server, 0, $peer)) !== false) {
            stream_set_blocking($connection, false);

            $client = [
                'socket'     => $connection,
                'peer'       => (string) $peer,
                'commands'   => '',
                'pending'    => RtlTcp::encodeHeader(RtlTcp::TUNER_R820T, GAIN_STEPS),
                'frequency'  => 100000000,
                'sampleRate' => DEFAULT_SAMPLE_RATE,
                'handle'     => $capture?->open(),
                'since'      => microtime(true),
                'sent'       => 0,
            ];

            logLine($verbose, "connect from $peer");
        }

        continue;
    }

    foreach ($read as $socket) {
        $chunk = fread($socket, 8192);

        if ($chunk === false || ($chunk === '' && feof($socket))) {
            closeClient($client, $verbose);

            continue 2;
        }

        $client['commands'] .= $chunk;

        foreach (RtlTcp::decodeCommands($client['commands']) as $command) {
            applyCommand($client, $command['command'], $command['value'], $verbose);
        }
    }

    foreach ($write as $socket) {
        $written = @fwrite($socket, $client['pending']);

        if ($written === false) {
            closeClient($client, $verbose);

            continue 2;
        }

        $client['pending'] = (string) substr($client['pending'], $written);
    }

    if ($client['pending'] !== '') {
        continue;
    }

    // Two bytes a sample, released at the rate the client asked for. Only once the last
    // slice has gone: a reader that cannot keep up loses samples, as it would from a
    // dongle, rather than having them pile up here without limit.
    $byteRate = $client['sampleRate'] * 2;
    $due      = (int) ((microtime(true) - $client['since']) * $byteRate) - $client['sent'];

    if ($due > $byteRate) {
        $client['sent'] += $due - $byteRate;
        $due = $byteRate;
    }

    if ($due >= 4096) {
        $bytes = min($due, 1 << 18) & ~1;
        $onAir = $capture !== null && abs($client['frequency'] - $capture->getFrequency()) <= TUNING_TOLERANCE;

        $client['pending'] = $onAir ? $capture->read($client['handle'], $bytes) : substr(str_repeat($noise, intdiv($bytes, strlen($noise)) + 1), 0, $bytes);
        $client['sent'] += $bytes;
    }
}

/**
 * @param array<string, mixed> $client
 */
function applyCommand(array &$client, int $command, int $value, bool $verbose): void
{
    switch ($command) {
        case RtlTcp::SET_FREQUENCY:
            $client['frequency'] = $value;
            logLine($verbose, sprintf('%s frequency %.4f MHz', $client['peer'], $value / 1e6));

            return;

        case RtlTcp::SET_SAMPLE_RATE:
            if ($value > 0) {
                // The clock starts again, or the change of rate would look like a debt of
                // samples owed since the connection began.
                $client['sampleRate'] = $value;
                $client['since']      = microtime(true);
                $client['sent']       = 0;
            }

            logLine($verbose, sprintf('%s sample rate %d', $client['peer'], $value));

            return;

        default:
            logLine($verbose, sprintf('%s %s %d (ignored)', $client['peer'], RtlTcp::commandName($command), $value));
    }
}

/**
 * @param array<string, mixed>|null $client
 */
function closeClient(?array &$client, bool $verbose): void
{
    if ($client === null) {
        return;
    }

    logLine($verbose, "disconnect from {$client['peer']}");

    if (is_resource($client['handle'])) {
        fclose($client['handle']);
    }

    fclose($client['socket']);
    $client = null;
}

/**
 * An empty channel: samples scattered a little either side of the middle of their range.
 */
function noise(int $bytes): string
{
    $noise = '';

    for ($i = 0; $i < $bytes; $i++) {
        $noise .= chr(127 + random_int(-3, 4));
    }

    return $noise;
}

function logLine(bool $verbose, string $message): void
{
    if ($verbose) {
        echo date('H:i:s'), " $message\n";
    }
}

/**
 * A recording of raw I/Q samples, replayed as a station.
 */
class IqCapture
{
    /** The rate nrsc5 samples FM at, and so the rate its captures are recorded at. */
    private const SAMPLE_RATE = 1488375;

    private string $path;
    private int $frequency;
    private int $size;

    private function __construct(string $path, int $frequency, int $size)
    {
        $this->path      = $path;
        $this->frequency = $frequency;
        $this->size      = $size;
    }

    /**
     * @param float $frequency the station's frequency in MHz
     */
    public static function load(string $path, float $frequency): self
    {
        if (strncmp($path, '~/', 2) === 0) {
            $path = (getenv('HOME') ?: '') . substr($path, 1);
        }

        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException("Capture file not readable: $path");
        }

        $size = (int) filesize($path);

        // Shorter than this and nrsc5 would never find the station before it started over.
        if ($size < self::SAMPLE_RATE * 2 * 5) {
            throw new RuntimeException("Capture file too short to replay (it needs at least five seconds): $path");
        }

        $handle = fopen($path, 'rb');
        $magic  = (string) fread($handle, 6);
        fclose($handle);

        // The mistake this is here for: the nrsc5 sample is shipped compressed, and
        // compressed samples are just a different kind of noise.
        if ($magic === "\xFD7zXZ\x00") {
            throw new RuntimeException("Capture file is still compressed; unpack it first (xz -d): $path");
        }

        if ($frequency < 87.5 || $frequency > 108.0) {
            throw new RuntimeException("Not an FM frequency in MHz: $frequency");
        }

        return new self($path, (int) round($frequency * 1e6), $size - $size % 2);
    }

    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * @return int in Hz, as rtl_tcp is told it
     */
    public function getFrequency(): int
    {
        return $this->frequency;
    }

    public function getSeconds(): float
    {
        return $this->size / (self::SAMPLE_RATE * 2);
    }

    /**
     * @return resource
     */
    public function open()
    {
        return fopen($this->path, 'rb');
    }

    /**
     * Read exactly $bytes, starting over at the end of the recording.
     *
     * @param resource $handle
     */
    public function read($handle, int $bytes): string
    {
        $data = '';

        while (strlen($data) < $bytes) {
            // Never past the last whole sample: an odd byte would swap I and Q for
            // everything after it.
            $room  = $this->size - (int) ftell($handle);
            $chunk = $room <= 0 ? '' : fread($handle, min($bytes - strlen($data), $room));

            if ($chunk === false || $chunk === '') {
                rewind($handle);

                continue;
            }

            $data .= $chunk;
        }

        return $data;
    }
}
