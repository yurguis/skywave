<?php declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave\Ac3;

/**
 * Decoded header of one AC-3 sync frame (ATSC A/52).
 *
 * The sync frame starts with 0x0B 0x77; the fields decoded here are the
 * minimum needed to describe the audio service: sample rate, bitstream id,
 * bsmod, audio coding mode, and LFE-on. Channel layout reported here is
 * the ground truth — decoders trust the bitstream over the PMT descriptor.
 */
class SyncFrame
{
    public const SYNC_WORD = "\x0B\x77";

    private const FSCOD_HZ = [
        0 => 48000,
        1 => 44100,
        2 => 32000,
    ];

    /** A/52 Table 5.8 - acmod -> front/surround channel arrangement. */
    private const ACMOD_LAYOUT = [
        0 => '1+1',
        1 => '1/0',
        2 => '2/0',
        3 => '3/0',
        4 => '2/1',
        5 => '3/1',
        6 => '2/2',
        7 => '3/2',
    ];

    /** Main-channel count (excluding LFE) per acmod. */
    private const ACMOD_MAIN_CHANNELS = [
        0 => 2, 1 => 1, 2 => 2, 3 => 3, 4 => 3, 5 => 4, 6 => 4, 7 => 5,
    ];

    private int $fscod;
    private int $frmsizecod;
    private int $bsid;
    private int $bsmod;
    private int $acmod;
    private bool $lfeOn;

    private function __construct(int $fscod, int $frmsizecod, int $bsid, int $bsmod, int $acmod, bool $lfeOn)
    {
        $this->fscod      = $fscod;
        $this->frmsizecod = $frmsizecod;
        $this->bsid       = $bsid;
        $this->bsmod      = $bsmod;
        $this->acmod      = $acmod;
        $this->lfeOn      = $lfeOn;
    }

    /**
     * Decode one sync frame header. $bytes must start with the sync word
     * (0x0B 0x77) and contain at least 10 bytes. Returns null otherwise.
     */
    public static function decode(string $bytes): ?self
    {
        if (strlen($bytes) < 10 || substr($bytes, 0, 2) !== self::SYNC_WORD) {
            return null;
        }

        $b4 = ord($bytes[4]); // fscod(2) | frmsizecod(6)
        $b5 = ord($bytes[5]); // bsid(5)  | bsmod(3)
        $b6 = ord($bytes[6]); // acmod(3) | ...

        $fscod      = ($b4 >> 6) & 0x3;
        $frmsizecod = $b4 & 0x3F;
        $bsid       = ($b5 >> 3) & 0x1F;
        $bsmod      = $b5 & 0x7;
        $acmod      = ($b6 >> 5) & 0x7;

        // Reject implausible field values - 0x0B77 occurs in random payload bytes
        // too, so we need to rule out false sync matches. A/52 reserves fscod=3
        // and bsid=11..15, 17..31; bsid=16 is E-AC-3 (Annex E), which we can't
        // decode with these bit positions, so reject it too.
        if ($fscod === 3 || $bsid > 10 || $frmsizecod > 37) {
            return null;
        }

        $lfeOn = self::readLfeBit($bytes, $acmod);

        return new self($fscod, $frmsizecod, $bsid, $bsmod, $acmod, $lfeOn);
    }

    /**
     * The LFE-on flag sits at a variable offset after acmod: a few optional
     * mix-level / Dolby-Surround sub-fields can precede it depending on acmod.
     */
    private static function readLfeBit(string $bytes, int $acmod): bool
    {
        $bits = '';
        for ($i = 6; $i < 10; $i++) {
            $bits .= str_pad(decbin(ord($bytes[$i])), 8, '0', STR_PAD_LEFT);
        }

        $bitPos = 3; // already consumed acmod (3 bits)

        if (($acmod & 0x1) !== 0 && $acmod !== 1) {
            $bitPos += 2; // cmixlev
        }

        if (($acmod & 0x4) !== 0) {
            $bitPos += 2; // surmixlev
        }

        if ($acmod === 2) {
            $bitPos += 2; // dsurmod
        }

        return $bits[$bitPos] === '1';
    }

    public function getSampleRate(): ?int
    {
        return self::FSCOD_HZ[$this->fscod] ?? null;
    }

    public function getSampleRateLabel(): string
    {
        $hz = $this->getSampleRate();

        if ($hz === null) {
            return 'reserved';
        }

        return $hz % 1000 === 0
            ? sprintf('%d kHz', intdiv($hz, 1000))
            : sprintf('%.1f kHz', $hz / 1000);
    }

    public function getBsid(): int
    {
        return $this->bsid;
    }

    public function getBsmod(): int
    {
        return $this->bsmod;
    }

    public function getAcmod(): int
    {
        return $this->acmod;
    }

    public function getAcmodLayout(): string
    {
        return self::ACMOD_LAYOUT[$this->acmod] ?? sprintf('acmod %d', $this->acmod);
    }

    public function hasLfe(): bool
    {
        return $this->lfeOn;
    }

    /** Main channel count (excludes LFE). */
    public function getMainChannelCount(): int
    {
        return self::ACMOD_MAIN_CHANNELS[$this->acmod] ?? 0;
    }

    /** Conventional "N.M" channel-count label (e.g. "5.1", "2.0"). */
    public function getChannelCountLabel(): string
    {
        return sprintf('%d.%d', $this->getMainChannelCount(), $this->lfeOn ? 1 : 0);
    }

    /** Compact summary: "48 kHz, 5.1 (3/2 + LFE), bsid 6". */
    public function getSummary(): string
    {
        $layout = $this->getAcmodLayout();

        if ($this->lfeOn) {
            $layout .= ' + LFE';
        }

        return sprintf(
            '%s, %s (%s), bsid %d',
            $this->getSampleRateLabel(),
            $this->getChannelCountLabel(),
            $layout,
            $this->bsid
        );
    }
}
