<?php declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave\Descriptor;

use Skywave\Io\BinaryReader;

/**
 * ATSC A/65 §6.9.2 - Caption Service Descriptor.
 *
 * Lists closed-caption services associated with the elementary stream
 * (when attached at PMT ES level) or with the event (when attached in
 * EIT). Each service is either analog NTSC line-21 (CC1..CC4 etc.) or
 * digital CEA-708 (services 1..63).
 */
class CaptionServiceDescriptor
{
    public const DESCRIPTOR_ID   = 0x86;
    public const DESCRIPTOR_NAME = 'Caption Service Descriptor';

    public const CC_TYPE_LINE_21 = 0;
    public const CC_TYPE_DIGITAL = 1;

    /**
     * @var array<int, array{
     *     language: string,
     *     ccType: int,
     *     serviceNumber: ?int,
     *     line21Field: ?int,
     *     easyReader: bool,
     *     wideAspectRatio: bool,
     * }>
     */
    private array $services = [];

    public function __construct(string $data)
    {
        $reader = new BinaryReader($data);
        $reader->skipBits(3); // reserved
        $count = $reader->bits(5);

        for ($i = 0; $i < $count; $i++) {
            $language = $reader->bytes(3);

            $ccType = $reader->bits(1);
            $reader->skipBits(1); // reserved

            $serviceNumber = null;
            $line21Field   = null;
            if ($ccType === self::CC_TYPE_LINE_21) {
                $reader->skipBits(5); // reserved
                $line21Field = $reader->bits(1);
            } else {
                $serviceNumber = $reader->bits(6);
            }

            $easyReader      = $reader->bits(1) === 1;
            $wideAspectRatio = $reader->bits(1) === 1;
            $reader->skipBits(14); // reserved

            $this->services[] = [
                'language'        => $language,
                'ccType'          => $ccType,
                'serviceNumber'   => $serviceNumber,
                'line21Field'     => $line21Field,
                'easyReader'      => $easyReader,
                'wideAspectRatio' => $wideAspectRatio,
            ];
        }
    }

    public function getName(): string
    {
        return self::DESCRIPTOR_NAME;
    }

    /**
     * @return array<int, array{
     *     language: string,
     *     ccType: int,
     *     serviceNumber: ?int,
     *     line21Field: ?int,
     *     easyReader: bool,
     *     wideAspectRatio: bool,
     * }>
     */
    public function getServices(): array
    {
        return $this->services;
    }

    /**
     * Compact human label for the services, e.g. "CS1 eng, CS2 spa".
     */
    public function getSummary(): string
    {
        $parts = [];
        foreach ($this->services as $service) {
            if ($service['ccType'] === self::CC_TYPE_DIGITAL) {
                $tag = sprintf('CS%d', $service['serviceNumber'] ?? 0);
            } else {
                // Line 21: field 1 carries CC1/CC2, field 2 carries CC3/CC4.
                $tag = $service['line21Field'] === 1 ? 'CC1/2' : 'CC3/4';
            }
            $parts[] = $tag . ' ' . trim($service['language']);
        }

        return implode(', ', $parts);
    }
}
