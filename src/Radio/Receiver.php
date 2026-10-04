<?php

declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave\Radio;

use InvalidArgumentException;
use RuntimeException;

/**
 * The dongle radio is received with, and the commands that listen to it.
 *
 * A tuner here is nothing like an HDHomeRun. It hands over raw samples and does none of the
 * work, so there is no device to ask about channels or signal: nrsc5 demodulates, corrects
 * and decodes, and what it prints while doing so is the only account of the station there
 * is. This class only knows where the samples come from -- a dongle plugged into this
 * machine, or one shared by rtl_tcp somewhere else -- and how to say so to nrsc5.
 *
 * One dongle hears one frequency. Unlike a television multiplex, two listeners on different
 * stations need two dongles.
 *
 * Three kinds of station are tuned, and the mode says which:
 *
 *   hd   digital HD Radio on the FM band, decoded by nrsc5, carrying HD1 to HD8
 *   fm   analog FM, demodulated by rtlanalog, with RDS read out of it by redsea
 *   am   analog AM, which needs the dongle in direct sampling to reach at all
 *
 * Analog is off unless RTLANALOG names a demodulator, the way HD Radio is off without
 * nrsc5. Either can be present without the other.
 */
final class Receiver
{
    /** The FM broadcast band, in MHz. HD Radio in North America sits on the odd tenths. */
    public const MIN_FREQUENCY = 87.5;
    public const MAX_FREQUENCY = 108.0;

    /** The AM broadcast band, in MHz, because every frequency here is in MHz: 1.14 is
     *  1140 kHz. The page says kHz, which is how anybody tuning AM thinks of it. */
    public const MIN_AM_FREQUENCY = 0.53;
    public const MAX_AM_FREQUENCY = 1.70;

    /** What is being listened to, and so which program does the listening. */
    public const MODE_HD = 'hd';
    public const MODE_FM = 'fm';
    public const MODE_AM = 'am';

    public const MODES = [self::MODE_HD, self::MODE_FM, self::MODE_AM];

    /** What rtlanalog puts out, in samples a second: the FM multiplex, and AM audio. */
    public const MPX_RATE = 171_000;
    public const AM_RATE  = 16_000;

    /** HD1 to HD8, numbered from zero as nrsc5 numbers them. */
    public const MAX_PROGRAM = 7;

    private string $nrsc5;
    private ?string $rtlanalog;
    private ?string $redsea;
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
    public function __construct(string $nrsc5 = 'nrsc5', ?string $rtlTcp = null, ?int $device = null, ?float $gain = null, ?int $ppm = null, ?string $rtlanalog = null, ?string $redsea = null)
    {
        if ($rtlTcp !== null) {
            RtlTcp::parseAddress($rtlTcp);
        }

        $this->nrsc5     = $nrsc5;
        $this->rtlanalog = $rtlanalog;
        $this->redsea    = $redsea;
        $this->rtlTcp    = $rtlTcp;
        $this->device    = $device === null ? null : max(0, $device);
        $this->gain      = $gain;
        $this->ppm       = $ppm;
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
            $ppm !== null && is_numeric($ppm) ? (int) $ppm : null,
            $env('RTLANALOG'),
            $env('REDSEA')
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
        return self::locate($this->nrsc5);
    }

    /**
     * Where rtlanalog is, or null when analog radio is not set up.
     */
    public function analogBinary(): ?string
    {
        return $this->rtlanalog === null ? null : self::locate($this->rtlanalog);
    }

    /**
     * Where redsea is, or null. Only FM wants it, and only for the station's name and the
     * song: without it analog FM still plays, and says nothing about itself.
     */
    public function redseaBinary(): ?string
    {
        return $this->redsea === null ? null : self::locate($this->redsea);
    }

    /**
     * Whether analog can be tuned at all, which is a different question from HD Radio.
     */
    public function supportsAnalog(): bool
    {
        return $this->analogBinary() !== null;
    }

    /**
     * A program by path or by name on PATH, or null when it is not there to be run.
     */
    private static function locate(string $program): ?string
    {
        if (str_contains($program, '/') || str_contains($program, '\\')) {
            return is_executable($program) ? $program : null;
        }

        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
            foreach (['', '.exe'] as $suffix) {
                $candidate = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $program . $suffix;

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
     * The rtlanalog command that plays one analog station.
     *
     * Signed 16-bit mono goes to standard output, and what it is depends on the mode. AM is
     * audio, finished, at 16 kHz. FM is not audio at all but the multiplex, at 171 kHz, for
     * two readers at once: ffmpeg makes sound of it, and redsea reads the station's name and
     * the song off the subcarrier that would be gone had it been demodulated here.
     *
     * @return string[]
     */
    public function analogArguments(string $mode, float $frequency): array
    {
        $binary = $this->analogBinary();

        if ($binary === null) {
            throw new RuntimeException($this->whyAnalogMissing());
        }

        $mode = self::validateMode($mode);

        if ($mode === self::MODE_HD) {
            throw new InvalidArgumentException('HD Radio is nrsc5\'s to tune, not rtlanalog\'s');
        }

        $address   = $this->rtlTcpAddress();
        $arguments = [$binary, '-H', $address === null ? '127.0.0.1:1234' : "{$address['host']}:{$address['port']}"];

        if ($this->gain !== null) {
            array_push($arguments, '-g', self::number($this->gain));
        }

        if ($this->ppm !== null) {
            array_push($arguments, '-p', (string) $this->ppm);
        }

        // In Hz and whole, which is what the dongle is actually set to; MHz is the page's
        // way of talking about it, and a tenth of a MHz is a hundred thousand Hz.
        array_push(
            $arguments,
            '-M',
            $mode === self::MODE_AM ? 'am' : 'mpx',
            '-f',
            (string) (int) round(self::validateFrequency($frequency, $mode) * 1_000_000)
        );

        return $arguments;
    }

    /**
     * The redsea command that reads RDS out of the multiplex arriving on its input.
     *
     * @return string[]
     */
    public function redseaArguments(): array
    {
        $binary = $this->redseaBinary();

        if ($binary === null) {
            throw new RuntimeException('redsea is not installed; analog FM plays without it, but says nothing about itself');
        }

        return [$binary, '-r', (string) self::MPX_RATE, '-i', 'mpx'];
    }

    public function whyAnalogMissing(): string
    {
        return $this->rtlanalog === null
            ? 'Analog radio is off: RTLANALOG does not say where rtlanalog is'
            : "rtlanalog is not installed, or this server cannot run it ($this->rtlanalog); analog AM and FM need it";
    }

    /**
     * One of hd, fm or am.
     *
     * @param mixed $mode
     */
    public static function validateMode($mode): string
    {
        if (!is_string($mode) || !in_array($mode, self::MODES, true)) {
            throw new InvalidArgumentException('Expected "mode" to be one of ' . implode(', ', self::MODES));
        }

        return $mode;
    }

    /**
     * The band a mode is heard on.
     */
    public static function bandOf(string $mode): string
    {
        return self::validateMode($mode) === self::MODE_AM ? 'am' : 'fm';
    }

    /**
     * A frequency in MHz: to the tenth on FM, where 90.5 is a station and 90.53 is not, and
     * to the kilohertz on AM, where 1.14 MHz is the station the page calls 1140.
     *
     * @param mixed $frequency
     */
    public static function validateFrequency($frequency, string $mode = self::MODE_HD): float
    {
        if (!is_int($frequency) && !is_float($frequency)) {
            throw new InvalidArgumentException('Expected "frequency" in MHz, e.g. 90.5');
        }

        if (self::bandOf($mode) === 'am') {
            // Rounded to the kilohertz rather than the tenth of a MHz, which would turn
            // 1140 kHz into 1100 and tune the wrong station without saying so.
            $rounded = round((float) $frequency, 3);

            if ($rounded < self::MIN_AM_FREQUENCY || $rounded > self::MAX_AM_FREQUENCY) {
                throw new InvalidArgumentException(sprintf(
                    'AM stations are between %d and %d kHz, not %s',
                    (int) round(self::MIN_AM_FREQUENCY * 1000),
                    (int) round(self::MAX_AM_FREQUENCY * 1000),
                    self::number((float) $frequency * 1000)
                ));
            }

            return $rounded;
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
     * A frequency on whichever band it belongs to, for the times the band is not said.
     *
     * Removing a saved station is one: it is named by a number that is already in the
     * database, and the number says which band it is. The two cannot be confused -- AM
     * stops at 1.70 MHz and FM starts at 87.5 -- so there is nothing to guess at.
     *
     * @param mixed $frequency
     */
    public static function validateAnyFrequency($frequency): float
    {
        $number = is_int($frequency) || is_float($frequency) ? (float) $frequency : null;

        return self::validateFrequency(
            $frequency,
            $number !== null && $number <= self::MAX_AM_FREQUENCY ? self::MODE_AM : self::MODE_HD
        );
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
