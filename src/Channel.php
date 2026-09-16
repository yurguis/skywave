<?php declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave;

use Skywave\Descriptor\DescriptorFactory;
use Skywave\Io\BinaryReader;

class Channel
{
    private string $shortName;
    private string $channel;
    private string $modulationMode;
    private int    $frequency;
    private int    $programNumber;
    private bool   $hidden;
    private bool   $hideGuide;
    private string $serviceType;
    private int    $sourceId;
    /** @var object[] keyed by descriptor name */
    private array  $descriptors = [];

    public function __construct(
        string $shortName,
        string $channel,
        string $modulationMode,
        int $frequency,
        int $programNumber,
        bool $hidden,
        bool $hideGuide,
        string $serviceType,
        int $sourceId,
        string $descriptors
    ) {
        $this->shortName      = self::decodeShortName($shortName);
        $this->channel        = $channel;
        $this->modulationMode = $modulationMode;
        $this->frequency      = $frequency;
        $this->programNumber  = $programNumber;
        $this->hidden         = $hidden;
        $this->hideGuide      = $hideGuide;
        $this->serviceType    = $serviceType;
        $this->sourceId       = $sourceId;
        $this->parseDescriptors($descriptors);
    }

    public function getShortName(): string
    {
        return $this->shortName;
    }

    public function getChannel(): string
    {
        return $this->channel;
    }

    public function getModulationMode(): string
    {
        return $this->modulationMode;
    }

    public function getFrequency(): int
    {
        return $this->frequency;
    }

    public function getProgramNumber(): int
    {
        return $this->programNumber;
    }

    public function isHidden(): bool
    {
        return $this->hidden;
    }

    public function isHideGuide(): bool
    {
        return $this->hideGuide;
    }

    public function getServiceType(): string
    {
        return $this->serviceType;
    }

    public function getSourceId(): int
    {
        return $this->sourceId;
    }

    /** @return object[] */
    public function getDescriptors(): array
    {
        return $this->descriptors;
    }

    private function parseDescriptors(string $descriptors): void
    {
        if ($descriptors === '') {
            return;
        }

        $reader = new BinaryReader($descriptors);

        while (!$reader->eof()) {
            $tag     = $reader->uint8();
            $length  = $reader->uint8();
            $payload = $length > 0 ? $reader->bytes($length) : '';

            $descriptor = DescriptorFactory::create($tag, $payload);
            if ($descriptor === null) {
                continue;
            }

            $this->descriptors[$descriptor->getName()] = $descriptor;
        }
    }

    /**
     * `short_name` is 7 UCS-2 (effectively UTF-16BE) code points, often
     * padded with NUL or space. Decode to UTF-8 and trim padding.
     */
    private static function decodeShortName(string $raw): string
    {
        $decoded = mb_convert_encoding($raw, 'UTF-8', 'UTF-16BE');
        if ($decoded === false) {
            return $raw;
        }

        return trim($decoded, " \x00");
    }
}
