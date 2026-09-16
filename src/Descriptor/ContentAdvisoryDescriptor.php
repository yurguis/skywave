<?php declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave\Descriptor;

use Skywave\Io\BinaryReader;
use Skywave\Structure\MultipleStringStructure;

/**
 * ATSC A/65 §6.9.3 - Content Advisory Descriptor.
 *
 * Carries content ratings per rating_region. Spec-wise the meaning of
 * each `(rating_dimension, rating_value)` pair is defined by the RRT for
 * that region. The US region (0x01) uses the well-known TV Parental
 * Guidelines / MPAA layout below; until we decode the RRT we resolve
 * those values inline.
 */
class ContentAdvisoryDescriptor
{
    public const DESCRIPTOR_ID   = 0x87;
    public const DESCRIPTOR_NAME = 'Content Advisory Descriptor';

    public const REGION_US = 0x01;

    /** US TV Parental Guidelines (dimension 0). */
    private const US_TVPG = [
        1 => 'TV-G',
        2 => 'TV-PG',
        3 => 'TV-14',
        4 => 'TV-MA',
    ];

    /** US Children (dimension 5). */
    private const US_CHILDREN = [
        1 => 'TV-Y',
        2 => 'TV-Y7',
    ];

    /** US MPAA (dimension 7). */
    private const US_MPAA = [
        1 => 'G',
        2 => 'PG',
        3 => 'PG-13',
        4 => 'R',
        5 => 'NC-17',
        6 => 'X',
        7 => 'Not Rated',
    ];

    /**
     * @var array<int, array{dimensions: array<int, int>, description: string}>
     *      keyed by rating_region
     */
    private array $ratingRegions = [];

    public function __construct(string $data)
    {
        $reader = new BinaryReader($data);
        $reader->skipBits(2); // reserved
        $regionCount = $reader->bits(6);

        for ($i = 0; $i < $regionCount; $i++) {
            $region        = $reader->uint8();
            $dimensionsRaw = $reader->uint8();
            $dimensions    = [];

            for ($j = 0; $j < $dimensionsRaw; $j++) {
                $dim = $reader->uint8();
                $reader->skipBits(4); // reserved
                $val = $reader->bits(4);
                $dimensions[$dim] = $val;
            }

            $descriptionLength = $reader->uint8();
            $description       = '';
            if ($descriptionLength > 0) {
                $mss     = new MultipleStringStructure($reader->bytes($descriptionLength));
                $strings = $mss->getStrings();
                if ($strings !== []) {
                    $description = $strings['eng'] ?? (string) reset($strings);
                }
            }

            $this->ratingRegions[$region] = [
                'dimensions'  => $dimensions,
                'description' => $description,
            ];
        }
    }

    public function getName(): string
    {
        return self::DESCRIPTOR_NAME;
    }

    /**
     * @return array<int, array{dimensions: array<int, int>, description: string}>
     */
    public function getRatingRegions(): array
    {
        return $this->ratingRegions;
    }

    /**
     * Human-readable US rating: e.g. "TV-14 (D,L,V)", "TV-Y7 (FV)", or "PG-13".
     * Returns null when there's no US region entry or no recognized rating set.
     */
    public function getUsRatingLabel(): ?string
    {
        $region = $this->ratingRegions[self::REGION_US] ?? null;
        if ($region === null) {
            return null;
        }
        $dims = $region['dimensions'];

        // MPAA dominates if present (theatrical films broadcast on TV).
        if (!empty($dims[7])) {
            return self::US_MPAA[$dims[7]] ?? null;
        }

        $base = null;
        if (!empty($dims[5])) {
            // Children's content (TV-Y / TV-Y7) takes precedence over TVPG.
            $base = self::US_CHILDREN[$dims[5]] ?? null;
        } elseif (!empty($dims[0])) {
            $base = self::US_TVPG[$dims[0]] ?? null;
        }

        if ($base === null) {
            return null;
        }

        $sub = [];
        if (!empty($dims[1])) $sub[] = 'D';
        if (!empty($dims[2])) $sub[] = 'L';
        if (!empty($dims[3])) $sub[] = 'S';
        if (!empty($dims[4])) $sub[] = 'V';
        if (!empty($dims[6])) $sub[] = 'FV';

        return $sub === [] ? $base : $base . ' ' . implode(',', $sub);
    }
}
