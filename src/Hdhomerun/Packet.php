<?php declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave\Hdhomerun;

use InvalidArgumentException;
use Skywave\Hdhomerun\Exception\ProtocolException;

/**
 * Wire format of the HDHomeRun discovery (UDP) and control (TCP) protocol.
 *
 * Mirrors libhdhomerun's hdhomerun_pkt.c:
 * - frame:   type (u16 BE) | payload length (u16 BE) | payload | CRC-32 (u32 LE)
 * - payload: a sequence of TLVs, tag (u8) | length (1 or 2 byte var-length) | value
 *
 * The CRC covers the 4-byte header plus the payload and is the standard reflected
 * CRC-32 (poly 0xEDB88320), which is exactly what PHP's crc32() computes.
 */
class Packet
{
    public const PORT = 65001;

    public const TYPE_DISCOVER_REQ = 0x0002;
    public const TYPE_DISCOVER_RPY = 0x0003;
    public const TYPE_GETSET_REQ   = 0x0004;
    public const TYPE_GETSET_RPY   = 0x0005;

    public const TAG_DEVICE_TYPE                 = 0x01;
    public const TAG_DEVICE_ID                   = 0x02;
    public const TAG_GETSET_NAME                 = 0x03;
    public const TAG_GETSET_VALUE                = 0x04;
    public const TAG_ERROR_MESSAGE               = 0x05;
    public const TAG_TUNER_COUNT                 = 0x10;
    public const TAG_GETSET_LOCKKEY              = 0x15;
    public const TAG_LINEUP_URL                  = 0x27;
    public const TAG_STORAGE_URL                 = 0x28;
    public const TAG_DEVICE_AUTH_BIN_DEPRECATED  = 0x29;
    public const TAG_BASE_URL                    = 0x2A;
    public const TAG_DEVICE_AUTH_STR             = 0x2B;
    public const TAG_STORAGE_ID                  = 0x2C;
    public const TAG_MULTI_TYPE                  = 0x2D;

    public const DEVICE_TYPE_TUNER    = 0x00000001;
    public const DEVICE_TYPE_STORAGE  = 0x00000005;
    public const DEVICE_TYPE_WILDCARD = 0xFFFFFFFF;
    public const DEVICE_ID_WILDCARD   = 0xFFFFFFFF;

    /** Largest length a 2-byte var-length field can carry. */
    private const MAX_TLV_LENGTH = 0x7FFF;

    public static function encodeFrame(int $type, string $payload): string
    {
        $length = strlen($payload);

        if ($length > 0xFFFF) {
            throw new InvalidArgumentException("Frame payload too long: $length bytes");
        }

        $frame = pack('nn', $type, $length) . $payload;

        return $frame . pack('V', crc32($frame));
    }

    /**
     * Decode the frame at the start of $buffer.
     *
     * @return array{type: int, payload: string, size: int}|null null when $buffer
     *         does not hold a complete frame yet; `size` is the number of bytes consumed
     * @throws ProtocolException when the frame is complete but its CRC does not match
     */
    public static function decodeFrame(string $buffer): ?array
    {
        if (strlen($buffer) < 4) {
            return null;
        }

        $header = unpack('ntype/nlength', $buffer);
        $size   = 4 + $header['length'] + 4;

        if (strlen($buffer) < $size) {
            return null;
        }

        $expected = unpack('V', $buffer, 4 + $header['length'])[1];
        $actual   = crc32(substr($buffer, 0, 4 + $header['length']));

        if ($expected !== $actual) {
            throw new ProtocolException(sprintf('Frame CRC mismatch (expected 0x%08X, calculated 0x%08X)', $expected, $actual));
        }

        return [
            'type'    => $header['type'],
            'payload' => substr($buffer, 4, $header['length']),
            'size'    => $size,
        ];
    }

    public static function encodeTlv(int $tag, string $value): string
    {
        $length = strlen($value);

        if ($length <= 127) {
            $prefix = chr($length);
        } elseif ($length <= self::MAX_TLV_LENGTH) {
            $prefix = chr(($length & 0x7F) | 0x80) . chr($length >> 7);
        } else {
            throw new InvalidArgumentException("TLV value too long: $length bytes");
        }

        return chr($tag) . $prefix . $value;
    }

    /**
     * Split a payload into TLVs, in order. Tags may repeat. Decoding stops silently
     * at a truncated TLV, like libhdhomerun does.
     *
     * @return list<array{0: int, 1: string}> [tag, value] pairs
     */
    public static function decodeTlvs(string $payload): array
    {
        $tlvs  = [];
        $pos   = 0;
        $total = strlen($payload);

        while ($pos + 2 <= $total) {
            $tag    = ord($payload[$pos++]);
            $length = ord($payload[$pos++]);

            if ($length & 0x80) {
                if ($pos >= $total) {
                    break;
                }

                $length = ($length & 0x7F) | (ord($payload[$pos++]) << 7);
            }

            if ($pos + $length > $total) {
                break;
            }

            $tlvs[] = [$tag, substr($payload, $pos, $length)];
            $pos   += $length;
        }

        return $tlvs;
    }

    /**
     * Strip the NUL terminator (and anything after it) from a C string value.
     */
    public static function cString(string $value): string
    {
        $nul = strpos($value, "\0");

        return $nul === false ? $value : substr($value, 0, $nul);
    }
}
