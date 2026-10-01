<?php

declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave\Radio;

use RuntimeException;

/**
 * The rtl_tcp protocol: how an RTL-SDR dongle is shared over a network.
 *
 * It is small. On connecting, the server sends twelve bytes -- the magic "RTL0", the tuner
 * chip and how many gain steps it has -- and then raw samples without end: unsigned 8-bit
 * I and Q, interleaved, at whatever rate was last asked for. The client sends five-byte
 * commands whenever it likes: one byte naming the setting and a big-endian 32-bit value.
 * Nothing is ever acknowledged.
 *
 * Everything that turns those samples into sound is nrsc5's work, not this project's. This
 * exists so the page can say what dongle is at the other end, and so the simulator can stand
 * in for one.
 */
final class RtlTcp
{
    public const PORT  = 1234;
    public const MAGIC = 'RTL0';

    public const HEADER_SIZE  = 12;
    public const COMMAND_SIZE = 5;

    public const SET_FREQUENCY       = 0x01;
    public const SET_SAMPLE_RATE     = 0x02;
    public const SET_GAIN_MODE       = 0x03;
    public const SET_GAIN            = 0x04;
    public const SET_PPM             = 0x05;
    public const SET_IF_GAIN         = 0x06;
    public const SET_TEST_MODE       = 0x07;
    public const SET_AGC_MODE        = 0x08;
    public const SET_DIRECT_SAMPLING = 0x09;
    public const SET_OFFSET_TUNING   = 0x0A;
    public const SET_BIAS_TEE        = 0x0E;

    public const TUNER_R820T = 5;

    /** As librtlsdr numbers them. */
    private const TUNERS = [
        1 => 'E4000',
        2 => 'FC0012',
        3 => 'FC0013',
        4 => 'FC2580',
        5 => 'R820T',
        6 => 'R828D',
    ];

    private const COMMANDS = [
        self::SET_FREQUENCY       => 'frequency',
        self::SET_SAMPLE_RATE     => 'sample rate',
        self::SET_GAIN_MODE       => 'gain mode',
        self::SET_GAIN            => 'gain',
        self::SET_PPM             => 'frequency correction',
        self::SET_IF_GAIN         => 'IF gain',
        self::SET_TEST_MODE       => 'test mode',
        self::SET_AGC_MODE        => 'AGC mode',
        self::SET_DIRECT_SAMPLING => 'direct sampling',
        self::SET_OFFSET_TUNING   => 'offset tuning',
        self::SET_BIAS_TEE        => 'bias tee',
    ];

    public static function encodeHeader(int $tunerType, int $gainCount): string
    {
        return self::MAGIC . pack('NN', $tunerType, $gainCount);
    }

    /**
     * @return array{tunerType: int, gainCount: int}
     */
    public static function decodeHeader(string $header): array
    {
        if (strlen($header) < self::HEADER_SIZE || strncmp($header, self::MAGIC, 4) !== 0) {
            throw new RuntimeException('Not an rtl_tcp server: it did not open with "RTL0"');
        }

        $fields = unpack('NtunerType/NgainCount', substr($header, 4, 8));

        return ['tunerType' => (int) $fields['tunerType'], 'gainCount' => (int) $fields['gainCount']];
    }

    public static function encodeCommand(int $command, int $value): string
    {
        return pack('CN', $command, $value & 0xFFFFFFFF);
    }

    /**
     * Take every whole command off the front of a buffer, leaving what has only half arrived.
     *
     * @return list<array{command: int, value: int}>
     */
    public static function decodeCommands(string &$buffer): array
    {
        $commands = [];

        while (strlen($buffer) >= self::COMMAND_SIZE) {
            $fields     = unpack('Ccommand/Nvalue', $buffer);
            $commands[] = ['command' => (int) $fields['command'], 'value' => (int) $fields['value']];
            $buffer     = (string) substr($buffer, self::COMMAND_SIZE);
        }

        return $commands;
    }

    public static function tunerName(int $tunerType): string
    {
        return self::TUNERS[$tunerType] ?? "unknown tuner ($tunerType)";
    }

    public static function commandName(int $command): string
    {
        return self::COMMANDS[$command] ?? sprintf('command 0x%02X', $command);
    }

    /**
     * Split "host" or "host:port" as nrsc5's -H takes it.
     *
     * @return array{host: string, port: int}
     */
    public static function parseAddress(string $address): array
    {
        if (!preg_match('/^([A-Za-z0-9.-]+)(?::(\d{1,5}))?$/', trim($address), $match)) {
            throw new RuntimeException("Expected an rtl_tcp address as host or host:port, got: $address");
        }

        $port = isset($match[2]) ? (int) $match[2] : self::PORT;

        if ($port < 1 || $port > 65535) {
            throw new RuntimeException("Not a port number: $port");
        }

        return ['host' => $match[1], 'port' => $port];
    }
}
