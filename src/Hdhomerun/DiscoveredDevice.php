<?php declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave\Hdhomerun;

/**
 * A device that answered a discovery request, as described by its reply.
 */
class DiscoveredDevice
{
    private int $deviceId;
    private string $ip;
    /** @var int[] */
    private array $deviceTypes;
    private int $tunerCount;
    private ?string $deviceAuth;
    private ?string $baseUrl;
    private ?string $lineupUrl;
    private ?string $storageId;
    private ?string $storageUrl;

    /**
     * @param int[] $deviceTypes
     */
    public function __construct(
        int $deviceId,
        string $ip,
        array $deviceTypes,
        int $tunerCount,
        ?string $deviceAuth = null,
        ?string $baseUrl = null,
        ?string $lineupUrl = null,
        ?string $storageId = null,
        ?string $storageUrl = null
    ) {
        $this->deviceId    = $deviceId;
        $this->ip          = $ip;
        $this->deviceTypes = $deviceTypes;
        $this->tunerCount  = $tunerCount;
        $this->deviceAuth  = $deviceAuth;
        $this->baseUrl     = $baseUrl;
        $this->lineupUrl   = $lineupUrl;
        $this->storageId   = $storageId;
        $this->storageUrl  = $storageUrl;
    }

    /**
     * Build a device from a DISCOVER_RPY payload, applying the same fixups for old
     * firmware as libhdhomerun. Returns null when the reply names no device type.
     */
    public static function fromReply(string $payload, string $ip): ?self
    {
        $deviceTypes = [];
        $deviceId    = 0;
        $tunerCount  = 0;
        $deviceAuth  = null;
        $baseUrl     = null;
        $lineupUrl   = null;
        $storageId   = null;
        $storageUrl  = null;

        foreach (Packet::decodeTlvs($payload) as [$tag, $value]) {
            switch ($tag) {
                case Packet::TAG_DEVICE_TYPE:
                    if (strlen($value) === 4) {
                        $deviceTypes[] = unpack('N', $value)[1];
                    }
                    break;

                case Packet::TAG_MULTI_TYPE:
                    for ($i = 0; $i + 4 <= strlen($value); $i += 4) {
                        $deviceTypes[] = unpack('N', $value, $i)[1];
                    }
                    break;

                case Packet::TAG_DEVICE_ID:
                    if (strlen($value) === 4) {
                        $deviceId = unpack('N', $value)[1];
                    }
                    break;

                case Packet::TAG_TUNER_COUNT:
                    if (strlen($value) === 1) {
                        $tunerCount = ord($value);
                    }
                    break;

                case Packet::TAG_DEVICE_AUTH_STR:
                    $deviceAuth ??= Packet::cString($value);
                    break;

                case Packet::TAG_DEVICE_AUTH_BIN_DEPRECATED:
                    // 18 raw bytes rendered as 24 characters of URL-safe base64.
                    if (strlen($value) === 18) {
                        $deviceAuth ??= strtr(base64_encode($value), '+/', '-_');
                    }
                    break;

                case Packet::TAG_BASE_URL:
                    $baseUrl ??= Packet::cString($value);
                    break;

                case Packet::TAG_LINEUP_URL:
                    $lineupUrl ??= Packet::cString($value);
                    break;

                case Packet::TAG_STORAGE_ID:
                    $storageId ??= Packet::cString($value);
                    break;

                case Packet::TAG_STORAGE_URL:
                    $storageUrl ??= Packet::cString($value);
                    break;
            }
        }

        $deviceTypes = array_values(array_unique(array_filter(
            $deviceTypes,
            fn(int $type) => $type !== 0 && $type !== Packet::DEVICE_TYPE_WILDCARD
        )));
        sort($deviceTypes);

        if ($deviceTypes === []) {
            return null;
        }

        if (in_array(Packet::DEVICE_TYPE_TUNER, $deviceTypes, true)) {
            // Old firmware does not report a tuner count; infer it from the model family.
            if ($tunerCount === 0) {
                switch ($deviceId >> 20) {
                    case 0x102:
                        $tunerCount = 1;
                        break;

                    case 0x100:
                    case 0x101:
                    case 0x121:
                        $tunerCount = 2;
                        break;
                }
            }

            if ($baseUrl === null && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $baseUrl = "http://$ip:80";
            }
        }

        return new self($deviceId, $ip, $deviceTypes, $tunerCount, $deviceAuth, $baseUrl, $lineupUrl, $storageId, $storageUrl);
    }

    public function getDeviceId(): int
    {
        return $this->deviceId;
    }

    /**
     * Device ID as printed on the label and by hdhomerun_config, e.g. "1080ABCD".
     */
    public function getDeviceIdHex(): string
    {
        return sprintf('%08X', $this->deviceId);
    }

    public function getIp(): string
    {
        return $this->ip;
    }

    /**
     * @return int[] Packet::DEVICE_TYPE_* values
     */
    public function getDeviceTypes(): array
    {
        return $this->deviceTypes;
    }

    public function isTuner(): bool
    {
        return in_array(Packet::DEVICE_TYPE_TUNER, $this->deviceTypes, true);
    }

    public function isStorage(): bool
    {
        return in_array(Packet::DEVICE_TYPE_STORAGE, $this->deviceTypes, true);
    }

    /**
     * Tuner count from the discovery reply; 0 when the device did not say.
     */
    public function getTunerCount(): int
    {
        return $this->tunerCount;
    }

    /**
     * Legacy (pre-HTTP-streaming) models, identified by device ID range.
     */
    public function isLegacy(): bool
    {
        switch ($this->deviceId >> 20) {
            case 0x100: // TECH-US/TECH3-US
                return $this->deviceId < 0x10040000;

            case 0x120: // TECH3-EU
                return $this->deviceId < 0x12030000;

            case 0x101: // HDHR-US
            case 0x102: // HDHR-T1-US
            case 0x103: // HDHR3-US
            case 0x111: // HDHR3-DT
            case 0x121: // HDHR-EU
            case 0x122: // HDHR3-EU
                return true;

            default:
                return false;
        }
    }

    /**
     * Token for Silicondust's cloud APIs (e.g. guide data).
     */
    public function getDeviceAuth(): ?string
    {
        return $this->deviceAuth;
    }

    public function getBaseUrl(): ?string
    {
        return $this->baseUrl;
    }

    public function getLineupUrl(): ?string
    {
        return $this->lineupUrl;
    }

    public function getStorageId(): ?string
    {
        return $this->storageId;
    }

    public function getStorageUrl(): ?string
    {
        return $this->storageUrl;
    }
}
