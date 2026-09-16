<?php

declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave\Hdhomerun;

use InvalidArgumentException;
use Skywave\Hdhomerun\Exception\ConnectionException;
use Skywave\Hdhomerun\Exception\DeviceErrorException;
use Skywave\Hdhomerun\Exception\HdhomerunException;
use Skywave\Hdhomerun\Exception\ProtocolException;

/**
 * Get/set client for a device's control port (TCP 65001).
 *
 * Mirrors libhdhomerun's hdhomerun_control.c: one persistent connection, opened
 * lazily, and a request is retried once on a fresh connection if the send or
 * receive fails.
 */
class ControlClient
{
    private string $host;
    private int $port;
    private float $timeout;

    /** @var resource|null */
    private $socket = null;

    /**
     * @param float $timeout seconds allowed for connecting and for each reply
     */
    public function __construct(string $host, int $port = Packet::PORT, float $timeout = 2.5)
    {
        $this->host    = $host;
        $this->port    = $port;
        $this->timeout = $timeout;
    }

    public function __destruct()
    {
        $this->close();
    }

    public function getHost(): string
    {
        return $this->host;
    }

    /**
     * Read a variable, e.g. "/sys/model" or "/tuner0/status".
     *
     * @throws DeviceErrorException when the device rejects the request
     * @throws HdhomerunException   on connection or protocol failures
     */
    public function get(string $name): string
    {
        return $this->getSet($name, null, 0);
    }

    /**
     * Like get(), but returns null when the device rejects the request (typically
     * a variable this model or firmware does not support).
     */
    public function tryGet(string $name): ?string
    {
        try {
            return $this->get($name);
        } catch (DeviceErrorException $e) {
            return null;
        }
    }

    /**
     * Write a variable. Returns the value the device reports back.
     *
     * @param int $lockkey tuner lock key, 0 when the tuner is not locked by this client
     * @throws DeviceErrorException when the device rejects the request
     * @throws HdhomerunException   on connection or protocol failures
     */
    public function set(string $name, string $value, int $lockkey = 0): string
    {
        return $this->getSet($name, $value, $lockkey);
    }

    /**
     * Local IP address of the open control connection, i.e. the address the device
     * can reach this machine on. Useful for pointing a tuner's stream target here.
     */
    public function getLocalAddress(): string
    {
        $this->connect();

        $name = stream_socket_get_name($this->socket, false);

        if ($name === false) {
            throw new ConnectionException('Unable to read the local address of the control connection');
        }

        return trim(substr($name, 0, (int) strrpos($name, ':')), '[]');
    }

    public function close(): void
    {
        if ($this->socket !== null) {
            fclose($this->socket);
            $this->socket = null;
        }
    }

    private function getSet(string $name, ?string $value, int $lockkey): string
    {
        if (strpos($name, "\0") !== false || ($value !== null && strpos($value, "\0") !== false)) {
            throw new InvalidArgumentException('Variable names and values cannot contain NUL bytes');
        }

        if ($lockkey < 0 || $lockkey > 0xFFFFFFFF) {
            throw new InvalidArgumentException("Lock key out of range: $lockkey");
        }

        $payload = Packet::encodeTlv(Packet::TAG_GETSET_NAME, $name . "\0");

        if ($value !== null) {
            $payload .= Packet::encodeTlv(Packet::TAG_GETSET_VALUE, $value . "\0");
        }

        if ($lockkey !== 0) {
            $payload .= Packet::encodeTlv(Packet::TAG_GETSET_LOCKKEY, pack('N', $lockkey));
        }

        $reply = $this->request(Packet::TYPE_GETSET_REQ, $payload);

        foreach (Packet::decodeTlvs($reply) as [$tag, $data]) {
            if ($tag === Packet::TAG_GETSET_VALUE) {
                return Packet::cString($data);
            }

            if ($tag === Packet::TAG_ERROR_MESSAGE) {
                throw new DeviceErrorException($name, Packet::cString($data));
            }
        }

        throw new ProtocolException("Reply for $name carries neither a value nor an error message");
    }

    private function request(int $type, string $payload): string
    {
        $frame     = Packet::encodeFrame($type, $payload);
        $lastError = null;

        for ($attempt = 0; $attempt < 2; $attempt++) {
            // A failed connect is final; only a broken send/receive is worth a retry.
            $this->connect();

            try {
                $this->write($frame);
                $reply = $this->readFrame();
            } catch (HdhomerunException $e) {
                $this->close();
                $lastError = $e;

                continue;
            }

            if ($reply['type'] !== $type + 1) {
                $this->close();
                $lastError = new ProtocolException(sprintf('Unexpected reply frame type 0x%04X', $reply['type']));

                continue;
            }

            return $reply['payload'];
        }

        throw $lastError;
    }

    private function connect(): void
    {
        if ($this->socket !== null) {
            return;
        }

        $host   = filter_var($this->host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? "[$this->host]" : $this->host;
        $socket = @stream_socket_client("tcp://$host:$this->port", $errno, $errstr, $this->timeout);

        if ($socket === false) {
            throw new ConnectionException("Unable to connect to $this->host:$this->port: $errstr");
        }

        $this->socket = $socket;
    }

    private function write(string $data): void
    {
        $deadline = microtime(true) + $this->timeout;

        while ($data !== '') {
            stream_set_timeout($this->socket, ...self::splitSeconds(max(0.001, $deadline - microtime(true))));
            $written = @fwrite($this->socket, $data);

            if ($written === false || $written === 0) {
                throw new ConnectionException("Failed to send request to $this->host");
            }

            $data = (string) substr($data, $written);
        }
    }

    /**
     * @return array{type: int, payload: string, size: int}
     */
    private function readFrame(): array
    {
        $buffer   = '';
        $deadline = microtime(true) + $this->timeout;

        while (true) {
            $frame = Packet::decodeFrame($buffer);

            if ($frame !== null) {
                return $frame;
            }

            $remaining = $deadline - microtime(true);

            if ($remaining <= 0) {
                throw new ConnectionException("Timed out waiting for a reply from $this->host");
            }

            $read   = [$this->socket];
            $write  = null;
            $except = null;
            $ready  = @stream_select($read, $write, $except, ...self::splitSeconds($remaining));

            if ($ready === false) {
                throw new ConnectionException("Failed waiting for a reply from $this->host");
            }

            if ($ready === 0) {
                continue;
            }

            $chunk = fread($this->socket, 8192);

            if ($chunk === false || ($chunk === '' && feof($this->socket))) {
                throw new ConnectionException("Connection closed by $this->host");
            }

            $buffer .= $chunk;
        }
    }

    /**
     * @return array{0: int, 1: int} [seconds, microseconds]
     */
    private static function splitSeconds(float $seconds): array
    {
        $whole = (int) $seconds;

        return [$whole, (int) (($seconds - $whole) * 1000000)];
    }
}
