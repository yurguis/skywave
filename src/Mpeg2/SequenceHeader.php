<?php declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave\Mpeg2;

/**
 * Decoded MPEG-2 sequence_header (+ sequence_extension if present).
 *
 * Carries the picture dimensions, aspect ratio, frame rate, scan type
 * (progressive vs interlaced) and profile/level. ATSC mandates fixed
 * picture sizes (1920x1080, 1280x720, 704/640x480), so these fields are
 * enough to label a stream as 1080i / 720p / 480i / 480p etc.
 */
class SequenceHeader
{
    public const SEQUENCE_HEADER_CODE = "\x00\x00\x01\xB3";
    public const EXTENSION_START_CODE = "\x00\x00\x01\xB5";

    /** extension_start_code_identifier value for sequence_extension. */
    private const SEQUENCE_EXTENSION_ID = 1;

    /** [num, den] per frame_rate_code (MPEG-2 Table 6-4). */
    private const FRAME_RATE = [
        1 => [24000, 1001],
        2 => [24, 1],
        3 => [25, 1],
        4 => [30000, 1001],
        5 => [30, 1],
        6 => [50, 1],
        7 => [60000, 1001],
        8 => [60, 1],
    ];

    private const ASPECT_RATIO_LABEL = [
        1 => '1:1',
        2 => '4:3',
        3 => '16:9',
        4 => '2.21:1',
    ];

    /** profile_identification (sequence_extension, 3 bits). */
    private const PROFILE_LABEL = [
        5 => 'Simple',
        4 => 'Main',
        3 => 'SNR Scalable',
        2 => 'Spatially Scalable',
        1 => 'High',
    ];

    /** level_identification (sequence_extension, 4 bits). */
    private const LEVEL_LABEL = [
        10 => 'Low',
        8  => 'Main',
        6  => 'High 1440',
        4  => 'High',
    ];

    private int $width;
    private int $height;
    private int $aspectRatioInfo;
    private int $frameRateCode;
    private bool $progressive;
    private ?int $profile;
    private ?int $level;

    private function __construct(
        int $width,
        int $height,
        int $aspectRatioInfo,
        int $frameRateCode,
        bool $progressive,
        ?int $profile,
        ?int $level
    ) {
        $this->width           = $width;
        $this->height          = $height;
        $this->aspectRatioInfo = $aspectRatioInfo;
        $this->frameRateCode   = $frameRateCode;
        $this->progressive     = $progressive;
        $this->profile         = $profile;
        $this->level           = $level;
    }

    /**
     * Decode the first sequence header (and optional sequence extension)
     * found in $bytes. Returns null if not yet enough data is buffered or
     * the candidate looks implausible.
     */
    public static function decode(string $bytes): ?self
    {
        $headerPos = strpos($bytes, self::SEQUENCE_HEADER_CODE);

        if ($headerPos === false || $headerPos + 8 > strlen($bytes)) {
            return null;
        }

        $offset = $headerPos + 4;
        $b0 = ord($bytes[$offset]);
        $b1 = ord($bytes[$offset + 1]);
        $b2 = ord($bytes[$offset + 2]);
        $b3 = ord($bytes[$offset + 3]);

        $width           = ($b0 << 4) | (($b1 >> 4) & 0xF);
        $height          = (($b1 & 0xF) << 8) | $b2;
        $aspectRatioInfo = ($b3 >> 4) & 0xF;
        $frameRateCode   = $b3 & 0xF;

        if (!self::isPlausible($width, $height, $aspectRatioInfo, $frameRateCode)) {
            return null;
        }

        [$progressive, $profile, $level] = self::readSequenceExtension($bytes, $offset + 4);

        return new self($width, $height, $aspectRatioInfo, $frameRateCode, $progressive, $profile, $level);
    }

    /**
     * @return array{0: bool, 1: ?int, 2: ?int} [progressive, profile, level]
     */
    private static function readSequenceExtension(string $bytes, int $searchFrom): array
    {
        $extPos = strpos($bytes, self::EXTENSION_START_CODE, $searchFrom);

        if ($extPos === false || $extPos + 6 > strlen($bytes)) {
            // MPEG-1 streams have no extension; treat as interlaced (the safe default for ATSC SD).
            return [false, null, null];
        }

        $eb0 = ord($bytes[$extPos + 4]);
        $eb1 = ord($bytes[$extPos + 5]);

        $extensionId = ($eb0 >> 4) & 0xF;

        if ($extensionId !== self::SEQUENCE_EXTENSION_ID) {
            return [false, null, null];
        }

        $pli = (($eb0 & 0xF) << 4) | (($eb1 >> 4) & 0xF);
        $progressive = (($eb1 >> 3) & 0x1) === 1;

        return [$progressive, ($pli >> 4) & 0x7, $pli & 0xF];
    }

    private static function isPlausible(int $width, int $height, int $aspectRatio, int $frameRateCode): bool
    {
        if ($width < 16 || $width > 4096 || $height < 16 || $height > 4096) {
            return false;
        }

        if ($frameRateCode < 1 || $frameRateCode > 8) {
            return false;
        }

        // Aspect ratio 0 and 5..15 are reserved.
        return $aspectRatio >= 1 && $aspectRatio <= 4;
    }

    public function getWidth(): int
    {
        return $this->width;
    }

    public function getHeight(): int
    {
        return $this->height;
    }

    public function isProgressive(): bool
    {
        return $this->progressive;
    }

    public function getFrameRate(): ?float
    {
        $r = self::FRAME_RATE[$this->frameRateCode] ?? null;

        return $r === null ? null : $r[0] / $r[1];
    }

    public function getAspectRatioLabel(): ?string
    {
        return self::ASPECT_RATIO_LABEL[$this->aspectRatioInfo] ?? null;
    }

    public function getProfileLabel(): ?string
    {
        return $this->profile === null ? null : (self::PROFILE_LABEL[$this->profile] ?? null);
    }

    public function getLevelLabel(): ?string
    {
        return $this->level === null ? null : (self::LEVEL_LABEL[$this->level] ?? null);
    }

    /** Compact summary: "1920x1080i, 29.97 fps, 16:9, MPEG-2 Main@High". */
    public function getSummary(): string
    {
        $parts = [sprintf('%d×%d%s', $this->width, $this->height, $this->progressive ? 'p' : 'i')];

        $fps = $this->getFrameRate();

        if ($fps !== null) {
            $parts[] = sprintf('%.2f fps', $fps);
        }

        $aspect = $this->getAspectRatioLabel();

        if ($aspect !== null) {
            $parts[] = $aspect;
        }

        $profile = $this->getProfileLabel();
        $level   = $this->getLevelLabel();

        if ($profile !== null && $level !== null) {
            $parts[] = sprintf('MPEG-2 %s@%s', $profile, $level);
        } else {
            $parts[] = 'MPEG-2';
        }

        return implode(', ', $parts);
    }
}
