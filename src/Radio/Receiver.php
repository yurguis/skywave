<?php

declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave\Radio;

use InvalidArgumentException;
use RuntimeException;

/**
 * The dongle HD Radio is received with, and the nrsc5 command that listens to it.
 *
 * A tuner here is nothing like an HDHomeRun. It hands over raw samples and does none of the
 * work, so there is no device to ask about channels or signal: nrsc5 demodulates, corrects
 * and decodes, and what it prints while doing so is the only account of the station there
 * is. This class only knows where the samples come from -- a dongle plugged into this
 * machine, or one shared by rtl_tcp somewhere else -- and how to say so to nrsc5.
 *
 * One dongle hears one frequency. Unlike a television multiplex, two listeners on different
 * stations need two dongles.
 */
final class Receiver
{
    /** The FM broadcast band, in MHz. HD Radio in North America sits on the odd tenths. */
    public const MIN_FREQUENCY = 87.5;
    public const MAX_FREQUENCY = 108.0;

    /** HD1 to HD8, numbered from zero as nrsc5 numbers them. */
    public const MAX_PROGRAM = 7;

    private string $nrsc5;
    private ?string $rtlTcp;
    private ?int $device;
    private ?float $gain;
    private ?int $ppm;

    /**
     * @param string|null $rtlTcp "host" or "host:port" of an rtl_tcp server, or null for a dongle here
     * @param int|null    $device which dongle on this machine, counting from zero
     * @param float|null  $gain   tuner gain in dB, or null to let nrsc5 find one
     * @param int|null    $ppm    the dongle's frequency error, in parts per million
     */
    public function __construct(string $nrsc5 = 'nrsc5', ?string $rtlTcp = null, ?int $device = null, ?float $gain = null, ?int $ppm = null)
    {
        if ($rtlTcp !== null) {
            RtlTcp::parseAddress($rtlTcp);
        }

        $this->nrsc5  = $nrsc5;
        $this->rtlTcp = $rtlTcp;
        $this->device = $device === null ? null : max(0, $device);
        $this->gain   = $gain;
        $this->ppm    = $ppm;
    }

    /**
     * Settings from NRSC5, RADIO_RTL_TCP, RADIO_DEVICE, RADIO_GAIN and RADIO_PPM.
     *
     * Radio is off until it is told where its dongle is: RADIO_RTL_TCP for one shared over
     * the network, or RADIO_DEVICE for one plugged in here. Having nrsc5 installed is not
     * taken as a wish to use it: it says nothing about whether there is a dongle to use it with.
     */
    public static function fromEnvironment(): self
    {
        $env = static function (string $name): ?string {
            $value = getenv($name);

            return $value === false || trim($value) === '' ? null : trim($value);
        };

        $device = $env('RADIO_DEVICE');
        $gain   = $env('RADIO_GAIN');
        $ppm    = $env('RADIO_PPM');

        return new self(
            $env('NRSC5') ?? 'nrsc5',
            $env('RADIO_RTL_TCP'),
            $device !== null && ctype_digit($device) ? (int) $device : null,
            $gain !== null && is_numeric($gain) ? (float) $gain : null,
            $ppm !== null && is_numeric($ppm) ? (int) $ppm : null
        );
    }

    public function isConfigured(): bool
    {
        return $this->rtlTcp !== null || $this->device !== null;
    }

    /**
     * Where nrsc5 is, or null when it is not installed.
     */
    public function binary(): ?string
    {
        if (str_contains($this->nrsc5, '/') || str_contains($this->nrsc5, '\\')) {
            return is_executable($this->nrsc5) ? $this->nrsc5 : null;
        }

        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
            foreach (['', '.exe'] as $suffix) {
                $candidate = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $this->nrsc5 . $suffix;

                if ($directory !== '' && is_file($candidate) && is_executable($candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * What to say when binary() finds nothing.
     *
     * Names the path, because "not installed" is only one of the ways to get here: a
     * binary that is there and cannot be read or run by this server looks exactly the
     * same from inside, and the path is what tells the two apart.
     */
    public function whyMissing(): string
    {
        return "nrsc5 is not installed, or this server cannot run it ($this->nrsc5); HD Radio needs it to decode a station";
    }

    /**
     * The rtl_tcp server this listens through, or null for a dongle on this machine.
     *
     * @return array{host: string, port: int}|null
     */
    public function rtlTcpAddress(): ?array
    {
        return $this->rtlTcp === null ? null : RtlTcp::parseAddress($this->rtlTcp);
    }

    /**
     * What the page shows about where the radio comes from.
     *
     * @return array{source: string, label: string, host: ?string, port: ?int, device: ?int}
     */
    public function describe(): array
    {
        $address = $this->rtlTcpAddress();

        if ($address !== null) {
            return [
                'source' => 'rtl_tcp',
                'label'  => "rtl_tcp at {$address['host']}:{$address['port']}",
                'host'   => $address['host'],
                'port'   => $address['port'],
                'device' => null,
            ];
        }

        return [
            'source' => 'usb',
            'label'  => 'USB dongle ' . ($this->device ?? 0),
            'host'   => null,
            'port'   => null,
            'device' => $this->device ?? 0,
        ];
    }

    /**
     * The nrsc5 command that plays one program of one station.
     *
     * Audio goes to standard output as raw 16-bit stereo at 44.1 kHz, which is all nrsc5
     * ever produces, and everything it learns about the station goes to standard error.
     * Pictures the station sends -- album covers, its logo -- are written to $directory,
     * or not kept at all when there is none: a scan only wants to know who is there.
     *
     * @return string[]
     */
    public function arguments(float $frequency, int $program, ?string $directory): array
    {
        $binary = $this->binary();

        if ($binary === null) {
            throw new RuntimeException($this->whyMissing());
        }

        $arguments = [$binary];
        $address   = $this->rtlTcpAddress();

        if ($address !== null) {
            array_push($arguments, '-H', "{$address['host']}:{$address['port']}");
        } elseif ($this->device !== null) {
            array_push($arguments, '-d', (string) $this->device);
        }

        if ($this->gain !== null) {
            array_push($arguments, '-g', self::number($this->gain));
        }

        if ($this->ppm !== null) {
            array_push($arguments, '-p', (string) $this->ppm);
        }

        array_push($arguments, '-o', '-', '-t', 'raw');

        if ($directory !== null) {
            array_push($arguments, '--dump-aas-files', $directory);
        }

        return array_merge($arguments, [
            self::number(self::validateFrequency($frequency)),
            (string) self::validateProgram($program),
        ]);
    }

    /**
     * A frequency in MHz, to the tenth: 90.5, not 90.50000001.
     *
     * @param mixed $frequency
     */
    public static function validateFrequency($frequency): float
    {
        if (!is_int($frequency) && !is_float($frequency)) {
            throw new InvalidArgumentException('Expected "frequency" in MHz, e.g. 90.5');
        }

        $rounded = round((float) $frequency, 1);

        if ($rounded < self::MIN_FREQUENCY || $rounded > self::MAX_FREQUENCY) {
            throw new InvalidArgumentException(sprintf(
                'FM stations are between %.1f and %.1f MHz, not %s',
                self::MIN_FREQUENCY,
                self::MAX_FREQUENCY,
                self::number((float) $frequency)
            ));
        }

        return $rounded;
    }

    /**
     * @param mixed $program
     */
    public static function validateProgram($program): int
    {
        if (!is_int($program) || $program < 0 || $program > self::MAX_PROGRAM) {
            throw new InvalidArgumentException('Expected "program" between 0 (HD1) and ' . self::MAX_PROGRAM . ' (HD' . (self::MAX_PROGRAM + 1) . ')');
        }

        return $program;
    }

    /**
     * A number as a command line wants it: no locale's comma, no trailing zeros.
     */
    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }
}
