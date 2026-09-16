<?php

declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave;

use Skywave\Io\BinaryReader;
use Skywave\Structure\MultipleStringStructure;

/**
 * ATSC A/65 §6.6 - Extended Text Table.
 *
 * Carries one extended (long-form) text per section, addressed by ETM_id.
 * For event ETMs the low two bits of ETM_id are `0b10`; bits 15..2 hold
 * the event_id and bits 31..16 hold the source_id.
 */
class ExtendedTextTable extends SectionTable
{
    private const ETM_ID_KIND_CHANNEL = 0b00;
    private const ETM_ID_KIND_EVENT   = 0b10;

    private int $etmId                  = 0;
    private int $sectionNumber          = 0;
    private int $lastSectionNumber      = 0;
    private string $extendedTextMessage = '';

    public function parse(): void
    {
        $reader = new BinaryReader($this->buffer);

        $reader->skipBytes(2); // ETT_table_id_extension
        $reader->skipBytes(1); // reserved(2) + version_number(5) + current_next_indicator(1)
        $this->sectionNumber     = $reader->uint8();
        $this->lastSectionNumber = $reader->uint8();
        $reader->skipBytes(1); // protocol_version
        $this->etmId = $reader->uint32();

        // Fixed-field bytes consumed = 10, CRC_32 = 4 at the end.
        $messageBytes = $this->sectionLength - 10 - 4;
        if ($messageBytes <= 0) {
            return;
        }

        $mss     = new MultipleStringStructure($reader->bytes($messageBytes));
        $strings = $mss->getStrings();
        if ($strings === []) {
            return;
        }

        $this->extendedTextMessage = $strings['eng'] ?? (string) reset($strings);
    }

    public function getEtmId(): int
    {
        return $this->etmId;
    }
    public function getSourceId(): int
    {
        return ($this->etmId >> 16) & 0xFFFF;
    }
    public function getEventId(): int
    {
        return ($this->etmId >> 2) & 0x3FFF;
    }
    public function isEventEtm(): bool
    {
        return ($this->etmId & 0x3) === self::ETM_ID_KIND_EVENT;
    }
    public function isChannelEtm(): bool
    {
        return ($this->etmId & 0x3) === self::ETM_ID_KIND_CHANNEL;
    }
    public function getSectionNumber(): int
    {
        return $this->sectionNumber;
    }
    public function getLastSectionNumber(): int
    {
        return $this->lastSectionNumber;
    }
    public function getExtendedTextMessage(): string
    {
        return $this->extendedTextMessage;
    }
}
