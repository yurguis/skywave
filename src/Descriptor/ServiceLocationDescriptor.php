<?php

declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave\Descriptor;

use Skywave\Io\BinaryReader;
use Skywave\Stream;

class ServiceLocationDescriptor
{
    public const DESCRIPTOR_ID   = 0xa1;
    public const DESCRIPTOR_NAME = 'Service Location Descriptor';

    private int $pcrPid;

    /** @var Stream[] */
    private array $streams = [];

    public function __construct(string $descriptor)
    {
        $reader = new BinaryReader($descriptor);

        $reader->skipBits(3); // reserved
        $this->pcrPid = $reader->bits(13);

        $numberElements = $reader->uint8();
        for ($i = 0; $i < $numberElements; $i++) {
            $streamType = $reader->uint8();
            $reader->skipBits(3); // reserved
            $pid      = $reader->bits(13);
            $language = $reader->bytes(3);

            $this->streams[] = new Stream($streamType, $pid, $language);
        }
    }

    public function getName(): string
    {
        return self::DESCRIPTOR_NAME;
    }

    public function getPcrPid(): int
    {
        return $this->pcrPid;
    }

    /** @return Stream[] */
    public function getStreams(): array
    {
        return $this->streams;
    }
}
