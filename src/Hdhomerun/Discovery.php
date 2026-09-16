<?php

declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave\Hdhomerun;

use InvalidArgumentException;
use Skywave\Hdhomerun\Exception\ConnectionException;
use Skywave\Hdhomerun\Exception\ProtocolException;

/**
 * Finds devices over UDP (port 65001), like libhdhomerun's hdhomerun_discover.c.
 *
 * Broadcast discovery only reaches devices on the same layer-2 network. From inside
 * Docker that means host networking; otherwise use findDeviceAt() with a known IP.
 */
class Discovery
{
    private const AF_INET = 2;

    private float $timeout;
    private int $attempts;

    /**
     * @param float $timeout  seconds to collect replies after each request
     * @param int   $attempts requests to send before giving up when nothing answers
     */
    public function __construct(float $timeout = 0.2, int $attempts = 2)
    {
        $this->timeout  = $timeout;
        $this->attempts = max(1, $attempts);
    }

    /**
     * Broadcast on every local IPv4 subnet and return every device that answers.
     *
     * @param int[] $deviceTypes Packet::DEVICE_TYPE_* values to ask for
     * @return DiscoveredDevice[]
     */
    public function findDevices(array $deviceTypes = [Packet::DEVICE_TYPE_TUNER], int $deviceId = Packet::DEVICE_ID_WILDCARD): array
    {
        return $this->discover(self::getBroadcastAddresses(), $deviceTypes, $deviceId, false);
    }

    /**
     * Ask a single IP address whether an HDHomeRun lives there.
     *
     * @param int[] $deviceTypes Packet::DEVICE_TYPE_* values to ask for
     */
    public function findDeviceAt(string $ip, array $deviceTypes = [Packet::DEVICE_TYPE_WILDCARD], int $deviceId = Packet::DEVICE_ID_WILDCARD): ?DiscoveredDevice
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            throw new InvalidArgumentException("Not an IPv4 address: $ip");
        }

        return $this->discover([$ip], $deviceTypes, $deviceId, true)[0] ?? null;
    }

    /**
     * Ask every address on one device's network whether an HDHomeRun lives there.
     *
     * Broadcast discovery does not leave a container on Docker Desktop, but a request to
     * each address does. All 254 go out before any reply is collected, so the whole
     * network answers in about as long as one address would.
     *
     * @param string $ip any address on the network to search, e.g. a tuner already known
     * @param int[] $deviceTypes Packet::DEVICE_TYPE_* values to ask for
     * @return DiscoveredDevice[]
     */
    public function findDevicesNear(string $ip, array $deviceTypes = [Packet::DEVICE_TYPE_TUNER], int $deviceId = Packet::DEVICE_ID_WILDCARD): array
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            throw new InvalidArgumentException("Not an IPv4 address: $ip");
        }

        $network   = implode('.', array_slice(explode('.', $ip), 0, 3));
        $addresses = [];

        for ($host = 1; $host < 255; $host++) {
            $addresses[] = "$network.$host";
        }

        return $this->discover($addresses, $deviceTypes, $deviceId, false);
    }

    /**
     * @param int[] $deviceTypes
     */
    public static function buildRequest(array $deviceTypes, int $deviceId = Packet::DEVICE_ID_WILDCARD): string
    {
        if ($deviceTypes === []) {
            throw new InvalidArgumentException('At least one device type is required');
        }

        if (count($deviceTypes) === 1) {
            $payload = Packet::encodeTlv(Packet::TAG_DEVICE_TYPE, pack('N', $deviceTypes[0]));
        } else {
            $payload = Packet::encodeTlv(Packet::TAG_MULTI_TYPE, pack('N*', ...$deviceTypes));
        }

        if ($deviceId !== Packet::DEVICE_ID_WILDCARD) {
            $payload .= Packet::encodeTlv(Packet::TAG_DEVICE_ID, pack('N', $deviceId));
        }

        return Packet::encodeFrame(Packet::TYPE_DISCOVER_REQ, $payload);
    }

    /**
     * Directed broadcast address of every IPv4 interface that is up, plus the
     * limited broadcast 255.255.255.255.
     *
     * @return string[]
     */
    public static function getBroadcastAddresses(): array
    {
        $addresses = [];

        foreach (net_get_interfaces() ?: [] as $interface) {
            if (($interface['up'] ?? true) === false) {
                continue;
            }

            foreach ($interface['unicast'] ?? [] as $address) {
                if (($address['family'] ?? null) !== self::AF_INET || !isset($address['address'], $address['netmask'])) {
                    continue;
                }

                $ip   = ip2long($address['address']);
                $mask = ip2long($address['netmask']);

                // Skip loopback and point-to-point (/32) interfaces such as VPN tunnels.
                if ($ip === false || $mask === false || ($ip >> 24) === 127 || $mask === 0xFFFFFFFF) {
                    continue;
                }

                $broadcast = ($ip | (~$mask & 0xFFFFFFFF)) & 0xFFFFFFFF;

                if ($broadcast !== 0 && $broadcast < 0xE0000000) {
                    $addresses[] = long2ip($broadcast);
                }
            }
        }

        $addresses[] = '255.255.255.255';

        return array_values(array_unique($addresses));
    }

    /**
     * @param string[] $destinations
     * @param int[]    $deviceTypes
     * @return DiscoveredDevice[]
     */
    private function discover(array $destinations, array $deviceTypes, int $deviceId, bool $stopOnFirst): array
    {
        $request = self::buildRequest($deviceTypes, $deviceId);
        $context = stream_context_create(['socket' => ['so_broadcast' => true]]);
        $socket  = @stream_socket_server('udp://0.0.0.0:0', $errno, $errstr, STREAM_SERVER_BIND, $context);

        if ($socket === false) {
            throw new ConnectionException("Unable to open a UDP socket for discovery: $errstr");
        }

        /** @var array<string, DiscoveredDevice> $devices */
        $devices = [];

        try {
            for ($attempt = 0; $attempt < $this->attempts && $devices === []; $attempt++) {
                foreach ($destinations as $destination) {
                    // A failed send on one interface must not stop the others.
                    @stream_socket_sendto($socket, $request, 0, "$destination:" . Packet::PORT);
                }

                $deadline = microtime(true) + $this->timeout;

                while (($remaining = $deadline - microtime(true)) > 0) {
                    $read   = [$socket];
                    $write  = null;
                    $except = null;
                    $ready  = @stream_select($read, $write, $except, (int) $remaining, (int) (fmod($remaining, 1.0) * 1000000));

                    if ($ready === false) {
                        break;
                    }

                    if ($ready === 0) {
                        continue;
                    }

                    $data = stream_socket_recvfrom($socket, 4096, 0, $peer);

                    if ($data === false || $data === '' || $peer === null) {
                        continue;
                    }

                    $device = self::parseReply($data, $peer, $deviceTypes, $deviceId);

                    if ($device === null) {
                        continue;
                    }

                    $key = $device->getDeviceId() !== 0
                        ? $device->getDeviceIdHex()
                        : $device->getIp() . '/' . ($device->getStorageId() ?? '');

                    $devices[$key] ??= $device;

                    if ($stopOnFirst) {
                        break;
                    }
                }
            }
        } finally {
            fclose($socket);
        }

        return array_values($devices);
    }

    /**
     * @param int[] $deviceTypes
     */
    private static function parseReply(string $data, string $peer, array $deviceTypes, int $deviceId): ?DiscoveredDevice
    {
        try {
            $frame = Packet::decodeFrame($data);
        } catch (ProtocolException $e) {
            return null;
        }

        if ($frame === null || $frame['type'] !== Packet::TYPE_DISCOVER_RPY) {
            return null;
        }

        $ip     = substr($peer, 0, (int) strrpos($peer, ':'));
        $device = DiscoveredDevice::fromReply($frame['payload'], $ip);

        if ($device === null) {
            return null;
        }

        if (!in_array(Packet::DEVICE_TYPE_WILDCARD, $deviceTypes, true)
            && array_intersect($deviceTypes, $device->getDeviceTypes()) === []) {
            return null;
        }

        if ($deviceId !== Packet::DEVICE_ID_WILDCARD && $device->getDeviceId() !== $deviceId) {
            return null;
        }

        return $device;
    }
}
