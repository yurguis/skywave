<?php

declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave\Hdhomerun;

/**
 * Parsed /tunerN/status, mirroring libhdhomerun's hdhomerun_tuner_status_t.
 */
class TunerStatus
{
    public const COLOR_NEUTRAL = 'neutral';
    public const COLOR_GREEN   = 'green';
    public const COLOR_YELLOW  = 'yellow';
    public const COLOR_RED     = 'red';

    private string $raw;
    private string $channel;
    private string $lock;
    private int $signalStrength;
    private int $signalToNoiseQuality;
    private int $symbolErrorQuality;
    private int $bitsPerSecond;
    private int $packetsPerSecond;
    private ?float $signalStrengthDbm;
    private ?float $signalToNoiseDb;

    public function __construct(string $raw)
    {
        $this->raw                  = $raw;
        $this->channel              = StatusString::value($raw, 'ch') ?? '';
        $this->lock                 = StatusString::value($raw, 'lock') ?? '';
        $this->signalStrength       = StatusString::int($raw, 'ss');
        $this->signalToNoiseQuality = StatusString::int($raw, 'snq');
        $this->symbolErrorQuality   = StatusString::int($raw, 'seq');
        $this->bitsPerSecond        = StatusString::int($raw, 'bps');
        $this->packetsPerSecond     = StatusString::int($raw, 'pps');
        $this->signalStrengthDbm    = StatusString::decibels($raw, 'ss');
        $this->signalToNoiseDb      = StatusString::decibels($raw, 'snq');
    }

    public function getRaw(): string
    {
        return $this->raw;
    }

    /**
     * Tuned channel, e.g. "auto:33", "atsc3:33:0+16", or "none".
     */
    public function getChannel(): string
    {
        return $this->channel;
    }

    /**
     * Demodulator lock, e.g. "8vsb", "qam256", "atsc3", "none", or "(ntsc)" style
     * parenthesized values for signals the tuner detects but cannot demodulate.
     */
    public function getLock(): string
    {
        return $this->lock;
    }

    /** Signal strength, 0-100. */
    public function getSignalStrength(): int
    {
        return $this->signalStrength;
    }

    /** Signal to noise quality, 0-100. */
    public function getSignalToNoiseQuality(): int
    {
        return $this->signalToNoiseQuality;
    }

    /** Symbol error quality, 0-100 (100 means no uncorrectable errors). */
    public function getSymbolErrorQuality(): int
    {
        return $this->symbolErrorQuality;
    }

    public function getBitsPerSecond(): int
    {
        return $this->bitsPerSecond;
    }

    public function getPacketsPerSecond(): int
    {
        return $this->packetsPerSecond;
    }

    /** Signal strength in dBm, when the firmware reports it. */
    public function getSignalStrengthDbm(): ?float
    {
        return $this->signalStrengthDbm;
    }

    /** Signal to noise ratio in dB, when the firmware reports it. */
    public function getSignalToNoiseDb(): ?float
    {
        return $this->signalToNoiseDb;
    }

    public function isSignalPresent(): bool
    {
        return $this->signalStrength >= 35;
    }

    public function isLockSupported(): bool
    {
        return $this->lock !== '' && $this->lock !== 'none' && $this->lock[0] !== '(';
    }

    public function isLockUnsupported(): bool
    {
        return $this->lock !== '' && $this->lock[0] === '(';
    }

    /**
     * Whether what the demodulator has locked onto is an MPEG transport stream.
     *
     * ATSC 3.0 is the one modulation here that is not. It carries IP packets inside ALP,
     * so a tuner locked to it has nothing to stream on the ordinary path and the device
     * answers 503 -- a lock in perfect health with no transport stream behind it, which
     * the status line shows plainly:
     *
     *     ch=auto:31 lock=atsc3 ss=62 snq=47 seq=0 bps=0 pps=0
     *
     * Deliberately separate from isLockSupported(), which is true here and should be:
     * the signal is fine, and reporting it as unlocked would be a different lie.
     */
    public function carriesTransportStream(): bool
    {
        return $this->isLockSupported() && $this->lock !== 'atsc3';
    }

    public function getSignalStrengthColor(): string
    {
        if (!$this->isLockSupported()) {
            return self::COLOR_NEUTRAL;
        }

        // Broadcast signals are usable at much lower levels than cable.
        [$yellowMin, $greenMin] = $this->isBroadcastLock() ? [50, 75] : [80, 90];

        if ($this->signalStrength >= $greenMin) {
            return self::COLOR_GREEN;
        }

        return $this->signalStrength >= $yellowMin ? self::COLOR_YELLOW : self::COLOR_RED;
    }

    public function getSignalToNoiseQualityColor(): string
    {
        if ($this->signalToNoiseQuality >= 70) {
            return self::COLOR_GREEN;
        }

        return $this->signalToNoiseQuality >= 50 ? self::COLOR_YELLOW : self::COLOR_RED;
    }

    public function getSymbolErrorQualityColor(): string
    {
        return $this->symbolErrorQuality >= 100 ? self::COLOR_GREEN : self::COLOR_RED;
    }

    private function isBroadcastLock(): bool
    {
        return in_array($this->lock, ['8vsb', 'atsc3'], true)
            || in_array(substr($this->lock, 0, 2), ['t8', 't7', 't6'], true);
    }
}
