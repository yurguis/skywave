<?php

declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave;

use Skywave\Io\BinaryReader;

class VirtualChannelTable extends SectionTable
{
    protected int $transportStreamId = 0;

    protected int $versionNumber = 0;

    protected array $channels = [];

    protected int $numberOfChannels = 0;

    public function parse(): void
    {
        $reader = new BinaryReader($this->buffer);

        $this->transportStreamId = $reader->uint16();

        $reader->skipBits(2); // reserved
        $this->versionNumber = $reader->bits(5);
        $reader->skipBits(1); // current_next_indicator
        $reader->skipBytes(1); // section_number
        $reader->skipBytes(1); // last_section_number
        $reader->skipBytes(1); // protocol_version

        $this->numberOfChannels = $reader->uint8();

        for ($i = 0; $i < $this->numberOfChannels; $i++) {
            $shortName = $reader->bytes(14); // short_name: 7 * 16 bits

            $reader->skipBits(4); // reserved '1111'
            $majorChannelNumber = $reader->bits(10);
            $minorChannelNumber = $reader->bits(10);

            $modulationMode   = $reader->uint8();
            $carrierFrequency = $reader->uint32();

            $reader->skipBytes(2); // channel_TSID
            $programNumber = $reader->uint16();

            // ETM_location(2) + access_controlled(1)
            $reader->skipBits(3);
            $hidden = $reader->bits(1);
            $reader->skipBits(2); // reserved (or path_select + out_of_band in older spec)
            $hideGuide = $reader->bits(1);
            $reader->skipBits(3); // reserved
            $serviceType = $reader->bits(6);

            $sourceId = $reader->uint16();

            $reader->skipBits(6); // reserved
            $descriptorsLength = $reader->bits(10);

            $descriptors = $descriptorsLength > 0 ? $reader->bytes($descriptorsLength) : '';

            $this->channels[] = new Channel(
                $shortName,
                sprintf('%d.%d', $majorChannelNumber, $minorChannelNumber),
                Parser::MODULATION_MODES[$modulationMode] ?? sprintf('0x%02x', $modulationMode),
                $carrierFrequency,
                $programNumber,
                (bool) $hidden,
                (bool) $hideGuide,
                Parser::SERVICE_TYPES[$serviceType] ?? (string) $serviceType,
                $sourceId,
                $descriptors
            );
        }
    }

    public function getChannels(): array
    {
        if (count($this->channels) < 2) {
            return $this->channels;
        }

        usort($this->channels, static function ($channel1, $channel2) {
            return $channel1->getProgramNumber() <=> $channel2->getProgramNumber();
        });

        return $this->channels;
    }

    public function getTransportStreamId(): int
    {
        return $this->transportStreamId;
    }

    public function getVersionNumber(): int
    {
        return $this->versionNumber;
    }

    public function getNumberOfChannels(): int
    {
        return $this->numberOfChannels;
    }
}
