<?php

declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

/**
 * Simulated HDHomeRun for development without hardware.
 *
 * Speaks the same protocols real devices do, so both this project's client and
 * Silicondust's hdhomerun_config can talk to it:
 * - discovery (UDP 65001) and get/set control requests (TCP 65001)
 * - HTTP streaming (TCP 5004): /tunerN/chX, /auto/chX, /tunerN/vM.m, /auto/vM.m,
 *   with an optional ?duration=<seconds>
 *
 * Without --capture, tuners lock on any channel below 52 with a made-up lineup and
 * there is no video to stream. With --capture, a raw MPEG-TS recording becomes the
 * only station on the air: it is carried on --capture-channel, its lineup comes from
 * the recording's PSIP tables, and HTTP streams replay it in a loop at its original
 * bitrate. Every other channel then reports no signal.
 *
 * Tuner lock keys are enforced like on a real device.
 *
 * Limits: streams always carry the full multiplex (no per-program filtering), and
 * timestamps jump back each time the recording loops.
 *
 * Usage:
 *   php tools/fake-hdhomerun.php [--bind=127.0.0.1] [--tuners=2] [--verbose]
 *                                [--capture=FILE.ts [--capture-channel=33]]
 */

use Skywave\Hdhomerun\Exception\ProtocolException;
use Skywave\Hdhomerun\Packet;
use Skywave\Parser;

require_once __DIR__ . '/../vendor/autoload.php';

$options = getopt('', ['bind:', 'tuners:', 'verbose', 'capture:', 'capture-channel:']);
$bind    = $options['bind'] ?? '127.0.0.1';
$verbose = isset($options['verbose']);
$capture = null;

if (isset($options['capture'])) {
    try {
        $capture = CaptureSource::load($options['capture'], (int) ($options['capture-channel'] ?? 33));
    } catch (RuntimeException $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
}

$device = new FakeDevice(max(1, min(8, (int) ($options['tuners'] ?? 2))), $capture);

$udp  = @stream_socket_server("udp://$bind:" . Packet::PORT, $errno, $errstr, STREAM_SERVER_BIND);
$tcp  = $udp === false ? false : @stream_socket_server("tcp://$bind:" . Packet::PORT, $errno, $errstr);
$http = $tcp === false ? false : @stream_socket_server("tcp://$bind:" . FakeDevice::HTTP_PORT, $errno, $errstr);

if ($udp === false || $tcp === false || $http === false) {
    fwrite(STDERR, "Unable to listen on $bind (ports " . Packet::PORT . ' and ' . FakeDevice::HTTP_PORT . "): $errstr\n");
    exit(1);
}

printf(
    "Fake HDHomeRun %08X (%d tuners) listening on %s:%d, HTTP streaming on port %d\n",
    $device->getDeviceId(),
    $device->getTunerCount(),
    $bind,
    Packet::PORT,
    FakeDevice::HTTP_PORT
);

if ($capture !== null) {
    printf(
        "Channel %d carries %s: %s (%.2f Mbps)\n",
        $capture->getChannel(),
        basename($capture->getPath()),
        implode(', ', $capture->getVirtualChannels()),
        $capture->getByteRate() * 8 / 1000000
    );
}

/** @var array<int, array{socket: resource, peer: string, buffer: string}> $controlClients */
$controlClients = [];

/**
 * HTTP clients go from "request" (reading headers) to "streaming" or "closing"
 * (flushing an error response).
 *
 * @var array<int, array<string, mixed>> $httpClients
 */
$httpClients = [];

while (true) {
    $read      = [$udp, $tcp, $http];
    $write     = [];
    $streaming = false;

    foreach ($controlClients as $client) {
        $read[] = $client['socket'];
    }

    foreach ($httpClients as $client) {
        $read[] = $client['socket'];

        if ($client['pending'] !== '') {
            $write[] = $client['socket'];
        }

        $streaming = $streaming || $client['state'] === 'streaming';
    }

    $except = null;

    // While streaming, wake up regularly to release the next slice of video on time.
    if (@stream_select($read, $write, $except, $streaming ? 0 : null, $streaming ? 10000 : null) === false) {
        break;
    }

    foreach ($read as $socket) {
        if ($socket === $udp) {
            $data = stream_socket_recvfrom($udp, 4096, 0, $peer);

            if ($data !== false && $peer !== null && ($reply = $device->handleDiscovery($data, $peer)) !== null) {
                stream_socket_sendto($udp, $reply, 0, $peer);
                logLine($verbose, "discover from $peer");
            }
        } elseif ($socket === $tcp) {
            if (($connection = @stream_socket_accept($tcp, 0, $peer)) !== false) {
                $controlClients[(int) $connection] = ['socket' => $connection, 'peer' => (string) $peer, 'buffer' => ''];
                logLine($verbose, "connect from $peer");
            }
        } elseif ($socket === $http) {
            if (($connection = @stream_socket_accept($http, 0, $peer)) !== false) {
                stream_set_blocking($connection, false);
                $httpClients[(int) $connection] = [
                    'socket'  => $connection,
                    'peer'    => (string) $peer,
                    'state'   => 'request',
                    'request' => '',
                    'pending' => '',
                ];
            }
        } elseif (isset($controlClients[(int) $socket])) {
            handleControlClient($device, $controlClients, (int) $socket, $verbose);
        } elseif (isset($httpClients[(int) $socket])) {
            $id    = (int) $socket;
            $chunk = fread($socket, 8192);

            if ($chunk === false || ($chunk === '' && feof($socket))) {
                closeHttpClient($device, $httpClients, $id, $verbose);

                continue;
            }

            if ($httpClients[$id]['state'] === 'request') {
                $httpClients[$id]['request'] .= $chunk;

                if (strpos($httpClients[$id]['request'], "\r\n\r\n") !== false) {
                    startHttpResponse($device, $httpClients[$id], $capture, $verbose);
                } elseif (strlen($httpClients[$id]['request']) > 8192) {
                    closeHttpClient($device, $httpClients, $id, $verbose);
                }
            }
        }
    }

    foreach ($write as $socket) {
        $id = (int) $socket;

        if (!isset($httpClients[$id])) {
            continue;
        }

        $written = @fwrite($socket, $httpClients[$id]['pending']);

        if ($written === false) {
            closeHttpClient($device, $httpClients, $id, $verbose);

            continue;
        }

        $httpClients[$id]['pending'] = (string) substr($httpClients[$id]['pending'], $written);
    }

    foreach (array_keys($httpClients) as $id) {
        $client = &$httpClients[$id];

        if ($client['state'] === 'closing' && $client['pending'] === '') {
            unset($client);
            closeHttpClient($device, $httpClients, $id, $verbose);

            continue;
        }

        if ($client['state'] !== 'streaming') {
            unset($client);

            continue;
        }

        $elapsed = microtime(true) - $client['started'];

        if ($client['duration'] !== null && $elapsed >= $client['duration']) {
            unset($client);
            closeHttpClient($device, $httpClients, $id, $verbose);

            continue;
        }

        // Release video at the recording's own bitrate, plus half a second of head
        // start, only once the previous slice has been written (a slow reader simply
        // falls behind instead of buffering here without limit).
        $due = (int) (($elapsed + 0.5) * $capture->getByteRate()) - $client['sent'];

        if ($client['pending'] === '' && $due >= 188 * 64) {
            $bytes             = min(intdiv($due, 188), 1024) * 188;
            $client['pending'] = $capture->read($client['handle'], $bytes);
            $client['sent'] += $bytes;
        }

        unset($client);
    }
}

/**
 * @param array<int, array{socket: resource, peer: string, buffer: string}> $clients
 */
function handleControlClient(FakeDevice $device, array &$clients, int $id, bool $verbose): void
{
    $socket = $clients[$id]['socket'];
    $chunk  = fread($socket, 8192);

    if ($chunk === false || ($chunk === '' && feof($socket))) {
        logLine($verbose, "disconnect from {$clients[$id]['peer']}");
        fclose($socket);
        unset($clients[$id]);

        return;
    }

    $clients[$id]['buffer'] .= $chunk;

    try {
        while (($frame = Packet::decodeFrame($clients[$id]['buffer'])) !== null) {
            $clients[$id]['buffer'] = (string) substr($clients[$id]['buffer'], $frame['size']);

            if ($frame['type'] !== Packet::TYPE_GETSET_REQ) {
                continue;
            }

            [$reply, $summary] = $device->handleGetSet($frame['payload'], peerIp($clients[$id]['peer']));
            fwrite($socket, $reply);
            logLine($verbose, "{$clients[$id]['peer']} $summary");
        }
    } catch (ProtocolException $e) {
        logLine($verbose, "{$clients[$id]['peer']} bad frame: {$e->getMessage()}");
        fclose($socket);
        unset($clients[$id]);
    }
}

/**
 * @param array<string, mixed> $client
 */
function startHttpResponse(FakeDevice $device, array &$client, ?CaptureSource $capture, bool $verbose): void
{
    $requestLine = strtok($client['request'], "\r\n");

    if (!preg_match('#^(GET|HEAD) (\S+) HTTP/1\.[01]$#', (string) $requestLine, $match)) {
        respondWithError($client, 400, 'Bad Request', null);

        return;
    }

    $path = (string) parse_url($match[2], PHP_URL_PATH);
    parse_str((string) parse_url($match[2], PHP_URL_QUERY), $query);

    $result = $device->startHttpStream($path, peerIp($client['peer']));

    if (isset($result['error'])) {
        logLine($verbose, "{$client['peer']} HTTP $path -> {$result['status']} {$result['error']}");
        respondWithError($client, $result['status'], $result['error'], $result['hdhomerunError'] ?? null);

        return;
    }

    logLine($verbose, "{$client['peer']} HTTP $path -> streaming on tuner {$result['tuner']}");

    $client['tuner']   = $result['tuner'];
    $client['pending'] = "HTTP/1.1 200 OK\r\nContent-Type: video/mpeg\r\nConnection: close\r\n\r\n";

    if ($match[1] === 'HEAD' || $capture === null) {
        $client['state'] = 'closing';

        return;
    }

    $client['state']    = 'streaming';
    $client['handle']   = $capture->open();
    $client['started']  = microtime(true);
    $client['sent']     = 0;
    $client['duration'] = isset($query['duration']) && ctype_digit((string) $query['duration']) && (int) $query['duration'] > 0
        ? (int) $query['duration']
        : null;
}

/**
 * @param array<string, mixed> $client
 */
function respondWithError(array &$client, int $status, string $reason, ?string $hdhomerunError): void
{
    $body = ($hdhomerunError ?? $reason) . "\n";

    $client['state']   = 'closing';
    $client['pending'] = "HTTP/1.1 $status $reason\r\n"
        . ($hdhomerunError === null ? '' : "X-HDHomeRun-Error: $hdhomerunError\r\n")
        . "Content-Type: text/plain\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n"
        . $body;
}

/**
 * @param array<int, array<string, mixed>> $clients
 */
function closeHttpClient(FakeDevice $device, array &$clients, int $id, bool $verbose): void
{
    $client = $clients[$id];

    if (isset($client['tuner'])) {
        $device->endHttpStream($client['tuner']);
        logLine($verbose, "{$client['peer']} HTTP stream on tuner {$client['tuner']} ended");
    }

    if (isset($client['handle'])) {
        fclose($client['handle']);
    }

    fclose($client['socket']);
    unset($clients[$id]);
}

function peerIp(string $peer): string
{
    return substr($peer, 0, (int) strrpos($peer, ':'));
}

function logLine(bool $verbose, string $message): void
{
    if ($verbose) {
        echo date('H:i:s'), " $message\n";
    }
}

/**
 * A raw MPEG-TS recording replayed as a live station.
 */
class CaptureSource
{
    private const PACKET_SIZE = 188;

    /** Fallback when the recording has no usable PCR: a full ATSC 1.0 multiplex. */
    private const DEFAULT_BITS_PER_SECOND = 19289600;

    private string $path;
    private int $channel;
    private string $streamInfo;
    /** @var string[] */
    private array $virtualChannels;
    private int $byteRate;

    /**
     * @param string[] $virtualChannels
     */
    private function __construct(string $path, int $channel, string $streamInfo, array $virtualChannels, int $byteRate)
    {
        $this->path            = $path;
        $this->channel         = $channel;
        $this->streamInfo      = $streamInfo;
        $this->virtualChannels = $virtualChannels;
        $this->byteRate        = $byteRate;
    }

    public static function load(string $path, int $channel): self
    {
        if (strncmp($path, '~/', 2) === 0) {
            $path = (getenv('HOME') ?: '') . substr($path, 1);
        }

        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException("Capture file not readable: $path");
        }

        if (filesize($path) < self::PACKET_SIZE * 1000) {
            throw new RuntimeException("Capture file too short to replay: $path");
        }

        $handle = fopen($path, 'rb');
        $parser = new Parser();

        while (($packet = fread($handle, self::PACKET_SIZE)) !== false && strlen($packet) === self::PACKET_SIZE) {
            if ($packet[0] !== "\x47") {
                fclose($handle);

                throw new RuntimeException("Not a raw MPEG-TS file (188-byte packets starting with 0x47): $path");
            }

            if ($parser->analyze($packet) === Parser::RETURN_TYPE_DONE) {
                break;
            }
        }

        fclose($handle);

        $lines           = [];
        $virtualChannels = [];
        $vct             = $parser->getVirtualChannelTable();
        $pat             = $parser->getProgramAssociationTable();

        if ($vct !== null) {
            foreach ($vct->getChannels() as $vc) {
                if ($vc->isHidden()) {
                    continue;
                }

                $lines[]           = sprintf('%d: %s %s', $vc->getProgramNumber(), $vc->getChannel(), $vc->getShortName());
                $virtualChannels[] = $vc->getChannel();
            }

            $lines[] = sprintf('tsid=0x%04X', $vct->getTransportStreamId());
        } elseif ($pat !== null) {
            foreach (array_keys($pat->getPrograms()) as $programNumber) {
                $lines[] = "$programNumber: 0";
            }

            $lines[] = sprintf('tsid=0x%04X', $pat->getTransportStreamId());
        } else {
            throw new RuntimeException("No PAT or virtual channel table found in $path");
        }

        return new self($path, $channel, implode("\n", $lines) . "\n", $virtualChannels, self::measureByteRate($path));
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getChannel(): int
    {
        return $this->channel;
    }

    public function getStreamInfo(): string
    {
        return $this->streamInfo;
    }

    /**
     * @return string[] e.g. ["4.1", "4.2"]
     */
    public function getVirtualChannels(): array
    {
        return $this->virtualChannels;
    }

    public function getByteRate(): int
    {
        return $this->byteRate;
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
            $chunk = fread($handle, $bytes - strlen($data));

            if ($chunk === false || $chunk === '') {
                rewind($handle);

                continue;
            }

            $data .= $chunk;
        }

        return $data;
    }

    /**
     * Bitrate from the first and last PCR of the same PID, so a replay runs at the
     * speed the station broadcast it.
     */
    private static function measureByteRate(string $path): int
    {
        $size   = (int) filesize($path);
        $window = min($size, 4000000);
        $first  = self::findPcr($path, 0, $window, null, false);
        $last   = $first === null ? null : self::findPcr($path, intdiv($size - $window, self::PACKET_SIZE) * self::PACKET_SIZE, $window, $first['pid'], true);

        if ($first === null || $last === null || $last['pcr'] <= $first['pcr']) {
            return intdiv(self::DEFAULT_BITS_PER_SECOND, 8);
        }

        $seconds = ($last['pcr'] - $first['pcr']) / 27000000;

        return (int) round(($last['offset'] - $first['offset']) / $seconds);
    }

    /**
     * @return array{pid: int, pcr: int, offset: int}|null
     */
    private static function findPcr(string $path, int $start, int $length, ?int $pid, bool $last): ?array
    {
        $handle = fopen($path, 'rb');
        fseek($handle, $start);
        $data = (string) fread($handle, $length);
        fclose($handle);

        $found = null;

        for ($offset = 0; $offset + self::PACKET_SIZE <= strlen($data); $offset += self::PACKET_SIZE) {
            $packetPid     = ((ord($data[$offset + 1]) & 0x1F) << 8) | ord($data[$offset + 2]);
            $hasAdaptation = (ord($data[$offset + 3]) & 0x20) !== 0;

            if (!$hasAdaptation || ord($data[$offset + 4]) === 0 || (ord($data[$offset + 5]) & 0x10) === 0) {
                continue;
            }

            if ($pid !== null && $packetPid !== $pid) {
                continue;
            }

            $b    = array_map('ord', str_split(substr($data, $offset + 6, 6)));
            $base = ($b[0] << 25) | ($b[1] << 17) | ($b[2] << 9) | ($b[3] << 1) | ($b[4] >> 7);

            $found = ['pid' => $packetPid, 'pcr' => $base * 300 + ((($b[4] & 0x01) << 8) | $b[5]), 'offset' => $start + $offset];

            if (!$last) {
                return $found;
            }
        }

        return $found;
    }
}

class FakeDevice
{
    public const HTTP_PORT = 5004;

    private const MODEL                  = 'hdhomerun5_atsc';
    private const VERSION                = '20250506';
    private const FEATURES               = "channelmap: us-bcast us-cable us-hrc us-irc\nmodulation: 8vsb qam256 qam64\nauto-modulation: auto auto6t auto6c qam\n";
    private const NO_SIGNAL_FROM_CHANNEL = 52;

    private int $deviceId;
    private ?CaptureSource $capture;
    /** @var array<int, array{channel: string, channelmap: string, program: string, target: string, filter: string, lockkey: int, lockOwner: string, streaming: bool}> */
    private array $tuners = [];

    public function __construct(int $tunerCount, ?CaptureSource $capture)
    {
        $this->deviceId = self::validDeviceId(0x1050ABC0);
        $this->capture  = $capture;

        for ($index = 0; $index < $tunerCount; $index++) {
            $this->tuners[] = [
                'channel'    => 'none',
                'channelmap' => 'us-bcast',
                'program'    => '0',
                'target'     => 'none',
                'filter'     => '0x0000-0x1FFF',
                'lockkey'    => 0,
                'lockOwner'  => 'none',
                'streaming'  => false,
            ];
        }
    }

    public function getDeviceId(): int
    {
        return $this->deviceId;
    }

    public function getTunerCount(): int
    {
        return count($this->tuners);
    }

    public function handleDiscovery(string $data, string $peer): ?string
    {
        try {
            $frame = Packet::decodeFrame($data);
        } catch (ProtocolException $e) {
            return null;
        }

        if ($frame === null || $frame['type'] !== Packet::TYPE_DISCOVER_REQ) {
            return null;
        }

        $typeMatches = false;
        $idMatches   = true;

        foreach (Packet::decodeTlvs($frame['payload']) as [$tag, $value]) {
            if ($tag === Packet::TAG_DEVICE_TYPE || $tag === Packet::TAG_MULTI_TYPE) {
                foreach (str_split($value, 4) as $type) {
                    if (strlen($type) === 4 && in_array(unpack('N', $type)[1], [Packet::DEVICE_TYPE_TUNER, Packet::DEVICE_TYPE_WILDCARD], true)) {
                        $typeMatches = true;
                    }
                }
            }

            if ($tag === Packet::TAG_DEVICE_ID && strlen($value) === 4) {
                $id        = unpack('N', $value)[1];
                $idMatches = $id === Packet::DEVICE_ID_WILDCARD || $id === $this->deviceId;
            }
        }

        if (!$typeMatches || !$idMatches) {
            return null;
        }

        $ip      = peerIp($peer);
        $payload = Packet::encodeTlv(Packet::TAG_DEVICE_TYPE, pack('N', Packet::DEVICE_TYPE_TUNER))
            . Packet::encodeTlv(Packet::TAG_DEVICE_ID, pack('N', $this->deviceId))
            . Packet::encodeTlv(Packet::TAG_TUNER_COUNT, chr(count($this->tuners)))
            . Packet::encodeTlv(Packet::TAG_DEVICE_AUTH_STR, 'fake-device-auth')
            . Packet::encodeTlv(Packet::TAG_BASE_URL, "http://$ip:80")
            . Packet::encodeTlv(Packet::TAG_LINEUP_URL, "http://$ip:80/lineup.json");

        return Packet::encodeFrame(Packet::TYPE_DISCOVER_RPY, $payload);
    }

    /**
     * @return array{0: string, 1: string} [reply frame, log summary]
     */
    public function handleGetSet(string $payload, string $peerIp): array
    {
        $name    = null;
        $value   = null;
        $lockkey = 0;

        foreach (Packet::decodeTlvs($payload) as [$tag, $data]) {
            if ($tag === Packet::TAG_GETSET_NAME) {
                $name = Packet::cString($data);
            } elseif ($tag === Packet::TAG_GETSET_VALUE) {
                $value = Packet::cString($data);
            } elseif ($tag === Packet::TAG_GETSET_LOCKKEY && strlen($data) === 4) {
                $lockkey = unpack('N', $data)[1];
            }
        }

        $name    = $name ?? '';
        $summary = $value === null ? "get $name" : "set $name $value" . ($lockkey !== 0 ? " (lockkey $lockkey)" : '');

        try {
            $result = $value === null ? $this->get($name) : $this->set($name, $value, $lockkey, $peerIp);
            $reply  = Packet::encodeTlv(Packet::TAG_GETSET_NAME, "$name\0") . Packet::encodeTlv(Packet::TAG_GETSET_VALUE, "$result\0");
        } catch (DomainException $e) {
            $reply = Packet::encodeTlv(Packet::TAG_GETSET_NAME, "$name\0") . Packet::encodeTlv(Packet::TAG_ERROR_MESSAGE, "ERROR: {$e->getMessage()}\0");
            $summary .= " -> ERROR: {$e->getMessage()}";
        }

        return [Packet::encodeFrame(Packet::TYPE_GETSET_RPY, $reply), $summary];
    }

    /**
     * Claim a tuner for an HTTP stream request path such as "/auto/v4.1".
     *
     * @return array{tuner: int}|array{status: int, error: string, hdhomerunError?: string}
     */
    public function startHttpStream(string $path, string $peerIp): array
    {
        if (!preg_match('#^/(?:auto|tuner(\d+))/(?:ch(\d+)|v(\d+(?:\.\d+)?))$#', $path, $match)) {
            return ['status' => 404, 'error' => 'Not Found'];
        }

        $channel = $match[2] !== '' ? (int) $match[2] : $this->channelForVirtual($match[3]);

        if ($channel === null) {
            return ['status' => 404, 'error' => 'Not Found', 'hdhomerunError' => '801 Unknown Channel'];
        }

        if ($match[1] !== '') {
            $index = (int) $match[1];

            if (!isset($this->tuners[$index])) {
                return ['status' => 404, 'error' => 'Not Found'];
            }

            if ($this->tuners[$index]['lockkey'] !== 0 || $this->tuners[$index]['streaming']) {
                return ['status' => 503, 'error' => 'Service Unavailable', 'hdhomerunError' => '804 Tuner In Use'];
            }
        } else {
            $index = null;

            foreach ($this->tuners as $candidate => $tuner) {
                if ($tuner['channel'] === 'none' && $tuner['lockkey'] === 0 && !$tuner['streaming']) {
                    $index = $candidate;

                    break;
                }
            }

            if ($index === null) {
                return ['status' => 503, 'error' => 'Service Unavailable', 'hdhomerunError' => '805 All Tuners In Use'];
            }
        }

        if (!$this->hasSignal($channel) || $this->capture === null) {
            return ['status' => 503, 'error' => 'Service Unavailable', 'hdhomerunError' => '807 No Video Data'];
        }

        $this->tuners[$index] = array_merge($this->tuners[$index], [
            'channel'   => "auto:$channel",
            'program'   => '0',
            'target'    => "http://$peerIp",
            'streaming' => true,
        ]);

        return ['tuner' => $index];
    }

    public function endHttpStream(int $index): void
    {
        $this->tuners[$index] = array_merge($this->tuners[$index], [
            'channel'   => 'none',
            'program'   => '0',
            'target'    => 'none',
            'streaming' => false,
        ]);
    }

    private function get(string $name): string
    {
        switch ($name) {
            case 'help':
                return "Supported configuration options:\n/sys/model\n/sys/hwmodel\n/sys/version\n/sys/features\n"
                    . "/tuner<n>/status\n/tuner<n>/streaminfo\n/tuner<n>/debug\n/tuner<n>/channel <modulation>:<ch>\n"
                    . "/tuner<n>/channelmap <map>\n/tuner<n>/program <program number>\n/tuner<n>/filter <filter>\n"
                    . "/tuner<n>/target <protocol>://<ip>:<port>\n/tuner<n>/lockkey\n";
            case '/sys/model':
                return self::MODEL;
            case '/sys/hwmodel':
                return sprintf('HDHR5-%dUS', count($this->tuners));
            case '/sys/version':
                return self::VERSION;
            case '/sys/features':
                return self::FEATURES;
        }

        [$index, $variable] = $this->parseTunerVariable($name);
        $tuner              = $this->tuners[$index];

        switch ($variable) {
            case 'status':
                return $this->status($tuner);
            case 'streaminfo':
                return $this->streamInfo($tuner);
            case 'debug':
                return 'tun: ' . $this->status($tuner) . "\ndev: resync=0 overflow=0\nts:  bps=0 te=0 crc=0\nnet: pps=0 err=0 stop=0\n";
            case 'channel':
            case 'channelmap':
            case 'program':
            case 'target':
            case 'filter':
                return $tuner[$variable];
            case 'lockkey':
                return $tuner['lockOwner'];
        }

        throw new DomainException('unknown getset variable');
    }

    private function set(string $name, string $value, int $lockkey, string $peerIp): string
    {
        [$index, $variable] = $this->parseTunerVariable($name);
        $tuner              = &$this->tuners[$index];

        if ($variable === 'lockkey') {
            if ($value === 'force') {
                $tuner['lockkey']   = 0;
                $tuner['lockOwner'] = 'none';

                return 'none';
            }

            if ($tuner['lockkey'] !== 0 && $tuner['lockkey'] !== $lockkey) {
                throw new DomainException("resource locked by {$tuner['lockOwner']}");
            }

            if ($value === 'none') {
                $tuner['lockkey']   = 0;
                $tuner['lockOwner'] = 'none';

                return 'none';
            }

            if (!ctype_digit($value) || (int) $value === 0 || (int) $value > 0xFFFFFFFF) {
                throw new DomainException('invalid lockkey');
            }

            $tuner['lockkey']   = (int) $value;
            $tuner['lockOwner'] = $peerIp;

            return $peerIp;
        }

        if (!in_array($variable, ['channel', 'channelmap', 'program', 'target', 'filter'], true)) {
            throw new DomainException('unknown getset variable');
        }

        if ($tuner['lockkey'] !== 0 && $tuner['lockkey'] !== $lockkey) {
            throw new DomainException("resource locked by {$tuner['lockOwner']}");
        }

        switch ($variable) {
            case 'channel':
                if ($value !== 'none' && !preg_match('/^(auto|auto6t|auto6c|8vsb|qam|qam64|qam256|atsc3):\d+$/', $value)) {
                    throw new DomainException('invalid channel');
                }
                $tuner['program'] = '0';

                break;

            case 'channelmap':
                if (!in_array($value, ['us-bcast', 'us-cable', 'us-hrc', 'us-irc'], true)) {
                    throw new DomainException('invalid channelmap');
                }

                break;

            case 'program':
                if (!ctype_digit($value)) {
                    throw new DomainException('invalid program');
                }

                break;

            case 'target':
                if ($value !== 'none' && !preg_match('#^(rtp|udp)://\d{1,3}(\.\d{1,3}){3}:\d{1,5}$#', $value)) {
                    throw new DomainException('invalid target');
                }

                break;
        }

        $tuner[$variable] = $value;

        return $value;
    }

    /**
     * @return array{0: int, 1: string} [tuner index, variable name]
     */
    private function parseTunerVariable(string $name): array
    {
        if (!preg_match('#^/tuner(\d+)/([a-z0-9]+)$#', $name, $match) || (int) $match[1] >= count($this->tuners)) {
            throw new DomainException('unknown getset variable');
        }

        return [(int) $match[1], $match[2]];
    }

    private function hasSignal(int $physical): bool
    {
        return $this->capture !== null
            ? $physical === $this->capture->getChannel()
            : $physical < self::NO_SIGNAL_FROM_CHANNEL;
    }

    private function channelForVirtual(string $virtual): ?int
    {
        if ($this->capture !== null) {
            return in_array($virtual, $this->capture->getVirtualChannels(), true) ? $this->capture->getChannel() : null;
        }

        // The made-up lineup numbers virtual channels after the physical channel.
        $major = (int) $virtual;

        return $major >= 2 && $major < self::NO_SIGNAL_FROM_CHANNEL ? $major : null;
    }

    /**
     * @param array{channel: string, streaming: bool} $tuner
     */
    private function status(array $tuner): string
    {
        $physical = self::physicalChannel($tuner);

        if ($physical === null) {
            return 'ch=none lock=none ss=0 snq=0 seq=0 bps=0 pps=0';
        }

        if (!$this->hasSignal($physical)) {
            return sprintf('ch=%s lock=none ss=%d snq=0 seq=0 bps=0 pps=0', $tuner['channel'], 10 + $physical % 20);
        }

        $modulation = explode(':', $tuner['channel'])[0];
        $lock       = in_array($modulation, ['auto', 'auto6t'], true) ? '8vsb' : $modulation;
        $strength   = 55 + ($physical * 7) % 45;
        $quality    = 45 + ($physical * 11) % 55;
        $bitsPerSec = $this->capture === null ? 19326304 : $this->capture->getByteRate() * 8;

        return sprintf(
            'ch=%s lock=%s ss=%d(%ddBm) snq=%d(%.1fdB) seq=100 bps=%d pps=%d',
            $tuner['channel'],
            $lock,
            $strength,
            $strength - 100,
            $quality,
            $quality * 0.35,
            $bitsPerSec,
            $tuner['streaming'] ? (int) round($bitsPerSec / 8 / 1316) : 0
        );
    }

    /**
     * @param array{channel: string} $tuner
     */
    private function streamInfo(array $tuner): string
    {
        $physical = self::physicalChannel($tuner);

        if ($physical === null || !$this->hasSignal($physical)) {
            return "none\n";
        }

        if ($this->capture !== null) {
            return $this->capture->getStreamInfo();
        }

        return sprintf(
            "3: %1\$d.1 FAKE%1\$d-HD\n4: %1\$d.2 FAKE%1\$d-SD\n5: %1\$d.3 FAKE%1\$d-PPV (encrypted)\ntsid=0x%2\$04X\n",
            $physical,
            0x0800 + $physical
        );
    }

    /**
     * @param array{channel: string} $tuner
     */
    private static function physicalChannel(array $tuner): ?int
    {
        return preg_match('/:(\d+)$/', $tuner['channel'], $match) ? (int) $match[1] : null;
    }

    /**
     * Replace the last hex digit so the ID passes libhdhomerun's checksum
     * (hdhomerun_discover_validate_device_id).
     */
    private static function validDeviceId(int $id): int
    {
        $lookup   = [0xA, 0x5, 0xF, 0x6, 0x7, 0xC, 0x1, 0xB, 0x9, 0x2, 0x8, 0xD, 0x4, 0x3, 0xE, 0x0];
        $checksum = 0;

        for ($shift = 28; $shift >= 4; $shift -= 4) {
            $nibble = ($id >> $shift) & 0x0F;
            $checksum ^= (($shift / 4) % 2 === 1) ? $lookup[$nibble] : $nibble;
        }

        return ($id & ~0x0F) | $checksum;
    }
}
