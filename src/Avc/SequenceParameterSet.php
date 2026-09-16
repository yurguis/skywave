<?php declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave\Avc;

use Exception;

/**
 * Decoded H.264/AVC Sequence Parameter Set.
 *
 * Carries profile/level, picture dimensions (post-cropping), scan type
 * (frame_mbs_only_flag) and - when the VUI is present - the frame rate.
 * Only the subset needed to label a stream is decoded; bit positions are
 * still advanced through scaling lists and most of the VUI so that timing
 * info can be reached when present.
 */
class SequenceParameterSet
{
    private const NAL_TYPE_SPS = 7;

    /** profile_idc values that gate the chroma_format_idc / scaling-list block. */
    private const HIGH_PROFILE_IDCS = [44, 83, 86, 100, 110, 118, 122, 128, 134, 135, 138, 139, 244];

    private const PROFILE_LABELS = [
        66  => 'Baseline',
        77  => 'Main',
        88  => 'Extended',
        100 => 'High',
        110 => 'High10',
        122 => 'High422',
        244 => 'High444',
    ];

    /** Table E-1: aspect_ratio_idc -> [sar_width, sar_height]. */
    private const SAR_TABLE = [
        1  => [1, 1],
        2  => [12, 11],
        3  => [10, 11],
        4  => [16, 11],
        5  => [40, 33],
        6  => [24, 11],
        7  => [20, 11],
        8  => [32, 11],
        9  => [80, 33],
        10 => [18, 11],
        11 => [15, 11],
        12 => [64, 33],
        13 => [160, 99],
        14 => [4, 3],
        15 => [3, 2],
        16 => [2, 1],
    ];

    private int $profileIdc;
    private int $levelIdc;
    private int $chromaFormatIdc = 1; // default 4:2:0 for Baseline/Main/Extended
    private int $width;
    private int $height;
    private bool $progressive;
    private ?float $frameRate = null;
    /** Display aspect ratio width/height ratio (e.g. 16/9 ~= 1.778) when computable. */
    private ?float $displayAspectRatio = null;

    private function __construct() {}

    /**
     * Find an SPS NAL in $bytes, un-stuff its RBSP, decode the fields we need.
     * Returns null if no SPS is found yet, or if the candidate fails sanity checks.
     */
    public static function decode(string $bytes): ?self
    {
        $rbsp = self::findSpsRbsp($bytes);

        if ($rbsp === null) {
            return null;
        }

        try {
            $sps = self::parseSps($rbsp);
        } catch (Exception $e) {
            // SPS straddles the buffer or contains an unexpected pattern; let
            // the sampler accumulate more bytes and try again.
            return null;
        }

        return self::isPlausible($sps) ? $sps : null;
    }

    /** Locate the SPS NAL, strip the start code and emulation-prevention bytes. */
    private static function findSpsRbsp(string $bytes): ?string
    {
        $offset = 0;
        $len    = strlen($bytes);

        while ($offset < $len) {
            $startCodePos = strpos($bytes, "\x00\x00\x01", $offset);

            if ($startCodePos === false) {
                return null;
            }

            $nalHeaderPos = $startCodePos + 3;

            if ($nalHeaderPos >= $len) {
                return null;
            }

            $nalUnitType = ord($bytes[$nalHeaderPos]) & 0x1F;

            if ($nalUnitType !== self::NAL_TYPE_SPS) {
                $offset = $nalHeaderPos;
                continue;
            }

            $nalEnd = strpos($bytes, "\x00\x00\x01", $nalHeaderPos + 1);

            if ($nalEnd === false) {
                // SPS may not be fully buffered yet; wait for more data.
                return null;
            }

            // Trim trailing zero of a 4-byte start code that prefixes the next NAL.
            if ($nalEnd > 0 && $bytes[$nalEnd - 1] === "\x00") {
                $nalEnd--;
            }

            $ebsp = substr($bytes, $nalHeaderPos + 1, $nalEnd - $nalHeaderPos - 1);

            return self::unstuffRbsp($ebsp);
        }

        return null;
    }

    /** Drop emulation-prevention bytes: replace any 00 00 03 with 00 00. */
    private static function unstuffRbsp(string $ebsp): string
    {
        $result = '';
        $len    = strlen($ebsp);
        $i      = 0;

        while ($i < $len) {
            if ($i + 2 < $len && $ebsp[$i] === "\x00" && $ebsp[$i + 1] === "\x00" && $ebsp[$i + 2] === "\x03") {
                $result .= "\x00\x00";
                $i      += 3;

                continue;
            }

            $result .= $ebsp[$i];
            $i++;
        }

        return $result;
    }

    private static function parseSps(string $rbsp): self
    {
        $r   = new BitReader($rbsp);
        $sps = new self();

        $sps->profileIdc = $r->bits(8);
        $r->bits(8); // constraint_setN_flag (6) + reserved (2)
        $sps->levelIdc  = $r->bits(8);
        $r->ue();    // seq_parameter_set_id

        if (in_array($sps->profileIdc, self::HIGH_PROFILE_IDCS, true)) {
            $sps->chromaFormatIdc = $r->ue();

            if ($sps->chromaFormatIdc === 3) {
                $r->bit(); // separate_colour_plane_flag
            }

            $r->ue(); // bit_depth_luma_minus8
            $r->ue(); // bit_depth_chroma_minus8
            $r->bit(); // qpprime_y_zero_transform_bypass_flag

            if ($r->bit() === 1) {
                // seq_scaling_matrix_present_flag
                $listCount = $sps->chromaFormatIdc !== 3 ? 8 : 12;

                for ($i = 0; $i < $listCount; $i++) {
                    if ($r->bit() === 1) {
                        self::skipScalingList($r, $i < 6 ? 16 : 64);
                    }
                }
            }
        }

        $r->ue(); // log2_max_frame_num_minus4
        $picOrderCntType = $r->ue();

        if ($picOrderCntType === 0) {
            $r->ue();
        } elseif ($picOrderCntType === 1) {
            $r->bit(); // delta_pic_order_always_zero_flag
            $r->se();  // offset_for_non_ref_pic
            $r->se();  // offset_for_top_to_bottom_field
            $cycle = $r->ue();

            for ($i = 0; $i < $cycle; $i++) {
                $r->se();
            }
        }

        $r->ue(); // max_num_ref_frames
        $r->bit(); // gaps_in_frame_num_value_allowed_flag

        $picWidthInMbsMinus1       = $r->ue();
        $picHeightInMapUnitsMinus1 = $r->ue();
        $frameMbsOnlyFlag          = $r->bit();
        $sps->progressive          = $frameMbsOnlyFlag === 1;

        if (!$sps->progressive) {
            $r->bit(); // mb_adaptive_frame_field_flag
        }

        $r->bit(); // direct_8x8_inference_flag

        $widthMb  = ($picWidthInMbsMinus1 + 1) * 16;
        $heightMb = ($picHeightInMapUnitsMinus1 + 1) * 16 * (2 - $frameMbsOnlyFlag);

        $cropLeft = $cropRight = $cropTop = $cropBottom = 0;

        if ($r->bit() === 1) {
            $cropLeft   = $r->ue();
            $cropRight  = $r->ue();
            $cropTop    = $r->ue();
            $cropBottom = $r->ue();
        }

        [$cropXUnit, $cropYUnit] = self::cropUnits($sps->chromaFormatIdc, $frameMbsOnlyFlag);

        $sps->width  = $widthMb - $cropXUnit * ($cropLeft + $cropRight);
        $sps->height = $heightMb - $cropYUnit * ($cropTop + $cropBottom);

        if ($r->bit() === 1) {
            // vui_parameters_present_flag
            self::parseVui($r, $sps);
        }

        return $sps;
    }

    /**
     * @return array{0: int, 1: int} [horizontalCropUnit, verticalCropUnit]
     *
     * Cropping offsets are in chroma sample units, so they get scaled to luma
     * by ChromaArrayType. See Rec. ITU-T H.264 Table 6-1.
     */
    private static function cropUnits(int $chromaFormatIdc, int $frameMbsOnlyFlag): array
    {
        if ($chromaFormatIdc === 0) {
            return [1, 2 - $frameMbsOnlyFlag];
        }

        if ($chromaFormatIdc === 1) {
            // 4:2:0
            return [2, 2 * (2 - $frameMbsOnlyFlag)];
        }

        if ($chromaFormatIdc === 2) {
            // 4:2:2
            return [2, 2 - $frameMbsOnlyFlag];
        }

        // 4:4:4
        return [1, 2 - $frameMbsOnlyFlag];
    }

    private static function parseVui(BitReader $r, self $sps): void
    {
        $sarWidth = $sarHeight = null;

        if ($r->bit() === 1) {
            // aspect_ratio_info_present_flag
            $aspectRatioIdc = $r->bits(8);

            if ($aspectRatioIdc === 255) {
                $sarWidth  = $r->bits(16);
                $sarHeight = $r->bits(16);
            } elseif (isset(self::SAR_TABLE[$aspectRatioIdc])) {
                [$sarWidth, $sarHeight] = self::SAR_TABLE[$aspectRatioIdc];
            }
        }

        if ($sarWidth !== null && $sarHeight !== null && $sarHeight > 0 && $sps->height > 0) {
            $sps->displayAspectRatio = ($sps->width * $sarWidth) / ($sps->height * $sarHeight);
        }

        if ($r->bit() === 1) {
            // overscan_info_present_flag
            $r->bit();
        }

        if ($r->bit() === 1) {
            // video_signal_type_present_flag
            $r->bits(3); // video_format
            $r->bit();   // video_full_range_flag

            if ($r->bit() === 1) {
                // colour_description_present_flag
                $r->bits(8); // colour_primaries
                $r->bits(8); // transfer_characteristics
                $r->bits(8); // matrix_coefficients
            }
        }

        if ($r->bit() === 1) {
            // chroma_loc_info_present_flag
            $r->ue();
            $r->ue();
        }

        if ($r->bit() === 1) {
            // timing_info_present_flag
            $numUnitsInTick = $r->bits(32);
            $timeScale      = $r->bits(32);
            $r->bit(); // fixed_frame_rate_flag

            if ($numUnitsInTick > 0 && $timeScale > 0) {
                $sps->frameRate = $timeScale / (2.0 * $numUnitsInTick);
            }
        }
    }

    private static function skipScalingList(BitReader $r, int $size): void
    {
        $lastScale = 8;
        $nextScale = 8;

        for ($j = 0; $j < $size; $j++) {
            if ($nextScale !== 0) {
                $delta     = $r->se();
                $nextScale = ($lastScale + $delta + 256) % 256;
            }

            if ($nextScale !== 0) {
                $lastScale = $nextScale;
            }
        }
    }

    private static function isPlausible(self $sps): bool
    {
        return $sps->width >= 16 && $sps->width <= 8192
            && $sps->height >= 16 && $sps->height <= 8192
            && $sps->levelIdc > 0 && $sps->levelIdc <= 255;
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
        return $this->frameRate;
    }

    public function getProfileLabel(): string
    {
        return self::PROFILE_LABELS[$this->profileIdc] ?? sprintf('Profile %d', $this->profileIdc);
    }

    public function getLevelLabel(): string
    {
        if ($this->levelIdc % 10 === 0) {
            return sprintf('L%d', intdiv($this->levelIdc, 10));
        }

        return sprintf('L%d.%d', intdiv($this->levelIdc, 10), $this->levelIdc % 10);
    }

    /** Compact summary: "1280x720p, 59.94 fps, AVC High@L4.0". */
    public function getSummary(): string
    {
        $parts = [sprintf('%d×%d%s', $this->width, $this->height, $this->progressive ? 'p' : 'i')];

        if ($this->frameRate !== null) {
            $parts[] = sprintf('%.2f fps', $this->frameRate);
        }

        if ($this->displayAspectRatio !== null) {
            $parts[] = self::formatDisplayAspectRatio($this->displayAspectRatio);
        }

        $parts[] = sprintf('AVC %s@%s', $this->getProfileLabel(), $this->getLevelLabel());

        return implode(', ', $parts);
    }

    private static function formatDisplayAspectRatio(float $ratio): string
    {
        // Snap to the common broadcast ratios within a small tolerance.
        if (abs($ratio - (16 / 9)) < 0.02) {
            return '16:9';
        }

        if (abs($ratio - (4 / 3)) < 0.02) {
            return '4:3';
        }

        return sprintf('%.2f:1', $ratio);
    }
}
