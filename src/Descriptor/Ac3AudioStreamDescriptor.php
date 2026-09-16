<?php declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave\Descriptor;

use Skywave\Io\BinaryReader;

/**
 * ATSC A/52 Annex A - AC-3 Audio Stream Descriptor.
 *
 * Attaches to AC-3 elementary streams in the PMT. Carries the
 * sample rate, peak bit rate, audio coding mode (mono / stereo /
 * 5.1 etc.), bit-stream mode (main / commentary / hearing-impaired
 * etc.), and Dolby Surround flag. The trailing fields (langcod,
 * text, language codes, additional_info) are not decoded here yet.
 */
class Ac3AudioStreamDescriptor
{
    public const DESCRIPTOR_ID   = 0x81;
    public const DESCRIPTOR_NAME = 'AC-3 Audio Stream Descriptor';

    private const SAMPLE_RATES = [
        0 => 48000,
        1 => 44100,
        2 => 32000,
    ];

    private const SAMPLE_RATE_LABELS = [
        0 => '48 kHz',
        1 => '44.1 kHz',
        2 => '32 kHz',
        3 => 'reserved',
        4 => '48/44.1 kHz',
        5 => '48/32 kHz',
        6 => '44.1/32 kHz',
        7 => '48/44.1/32 kHz',
    ];

    /** A/52 Annex A Table A4.7: exact-rate codes 0..18 (kbps). */
    private const BIT_RATES = [
        0  => 32,
        1  => 40,
        2  => 48,
        3  => 56,
        4  => 64,
        5  => 80,
        6  => 96,
        7  => 112,
        8  => 128,
        9  => 160,
        10 => 192,
        11 => 224,
        12 => 256,
        13 => 320,
        14 => 384,
        15 => 448,
        16 => 512,
        17 => 576,
        18 => 640,
    ];

    /** Audio coding mode = front/surround channel count (no LFE). */
    private const CHANNEL_MODES = [
        0 => '1+1 (dual mono)',
        1 => '1/0 (mono)',
        2 => '2/0 (stereo)',
        3 => '3/0',
        4 => '2/1',
        5 => '3/1',
        6 => '2/2',
        7 => '3/2 (5.0)',
        8 => '1.0',
    ];

    private const SURROUND_LABELS = [
        0 => 'not indicated',
        1 => 'not Dolby Surround',
        2 => 'Dolby Surround encoded',
        3 => 'reserved',
    ];

    /** bsmod meaning depends on the audio coding mode; full names from A/52 §5.4.2.1. */
    private const BSMOD_LABELS = [
        0 => 'main (complete)',
        1 => 'main (music & effects)',
        2 => 'visually impaired',
        3 => 'hearing impaired',
        4 => 'dialogue',
        5 => 'commentary',
        6 => 'emergency',
        7 => 'voice-over / karaoke',
    ];

    private int $sampleRateCode;
    private int $bsid;
    private int $bitRateCode;
    private int $surroundMode;
    private int $bsmod;
    private int $numChannels;
    private bool $fullSvc;

    public function __construct(string $data)
    {
        if ($data === '') {
            return;
        }

        $reader = new BinaryReader($data);
        $this->sampleRateCode = $reader->bits(3);
        $this->bsid           = $reader->bits(5);
        $this->bitRateCode    = $reader->bits(6);
        $this->surroundMode   = $reader->bits(2);
        $this->bsmod          = $reader->bits(3);
        $this->numChannels    = $reader->bits(4);
        $this->fullSvc        = $reader->bits(1) === 1;
        // Variable-length tail (langcod, mainid/asvcflags, textlen, text, lang_code,
        // lang_code_2, additional_info) is not surfaced yet.
    }

    public function getName(): string
    {
        return self::DESCRIPTOR_NAME;
    }

    /** Sample rate in Hz, or null when the code indicates "or" (variable). */
    public function getSampleRate(): ?int
    {
        return self::SAMPLE_RATES[$this->sampleRateCode] ?? null;
    }

    public function getSampleRateLabel(): string
    {
        return self::SAMPLE_RATE_LABELS[$this->sampleRateCode] ?? 'sample-rate code ' . $this->sampleRateCode;
    }

    /** Bit rate in kbps for exact-rate codes; null for upper-bound (CBR-cap) codes. */
    public function getBitRate(): ?int
    {
        return self::BIT_RATES[$this->bitRateCode] ?? null;
    }

    /** True if the bit rate is an upper bound (variable rate). */
    public function isBitRateUpperBound(): bool
    {
        return $this->bitRateCode >= 19;
    }

    public function getChannelMode(): string
    {
        return self::CHANNEL_MODES[$this->numChannels] ?? sprintf('mode %d', $this->numChannels);
    }

    public function getSurroundLabel(): string
    {
        return self::SURROUND_LABELS[$this->surroundMode] ?? '?';
    }

    public function getBsmodLabel(): string
    {
        return self::BSMOD_LABELS[$this->bsmod] ?? '?';
    }

    public function isDolbySurround(): bool
    {
        return $this->surroundMode === 2;
    }

    public function getBsid(): int
    {
        return $this->bsid;
    }

    public function isFullService(): bool
    {
        return $this->fullSvc;
    }

    /**
     * Compact one-line summary: "48 kHz, 192 kbps, 2/0 (stereo)".
     */
    public function getSummary(): string
    {
        $parts   = [];
        $parts[] = $this->getSampleRateLabel();

        $br = $this->getBitRate();

        if ($br !== null) {
            $parts[] = sprintf('%d kbps', $br);
        } elseif ($this->isBitRateUpperBound()) {
            $parts[] = 'VBR';
        }

        $parts[] = $this->getChannelMode();

        if ($this->isDolbySurround()) {
            $parts[] = 'Dolby Surround';
        }

        if ($this->bsmod !== 0) {
            $parts[] = $this->getBsmodLabel();
        }

        return implode(', ', $parts);
    }
}
