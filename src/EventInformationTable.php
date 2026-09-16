<?php declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave;

use Skywave\Descriptor\DescriptorFactory;
use Skywave\Io\BinaryReader;
use Skywave\Structure\MultipleStringStructure;

/**
 * ATSC A/65 §6.5 - Event Information Table.
 *
 * One EIT section carries the events for a single `source_id`
 * (i.e. one virtual channel). Multiple sections on the same EIT PID
 * with different `section_number`s are concatenated to recover the
 * full event list for that source.
 */
class EventInformationTable extends SectionTable
{
    private int $sourceId          = 0;
    private int $versionNumber     = 0;
    private int $sectionNumber     = 0;
    private int $lastSectionNumber = 0;

    /** @var Event[] */
    private array $events = [];

    public function parse(): void
    {
        $reader = new BinaryReader($this->buffer);

        $this->sourceId = $reader->uint16(); // table_id_extension

        $reader->skipBits(2); // reserved
        $this->versionNumber = $reader->bits(5);
        $reader->skipBits(1); // current_next_indicator (always '1' per spec)

        $this->sectionNumber     = $reader->uint8();
        $this->lastSectionNumber = $reader->uint8();
        $reader->skipBytes(1);   // protocol_version

        $numEvents = $reader->uint8();

        for ($i = 0; $i < $numEvents; $i++) {
            $reader->skipBits(2); // reserved
            $eventId       = $reader->bits(14);
            $startTime     = $reader->uint32();
            $reader->skipBits(2); // reserved
            $reader->skipBits(2); // ETM_location
            $lengthSeconds = $reader->bits(20);

            $titleLength = $reader->uint8();
            $titleBytes  = $titleLength > 0 ? $reader->bytes($titleLength) : '';
            $title       = $this->extractTitle($titleBytes);

            $reader->skipBits(4); // reserved
            $descriptorsLength = $reader->bits(12);
            $descriptors       = $descriptorsLength > 0
                ? $this->parseDescriptors($reader->bytes($descriptorsLength))
                : [];

            $this->events[] = new Event($eventId, $startTime, $lengthSeconds, $title, $descriptors);
        }
    }

    /**
     * @return object[] keyed by descriptor name
     */
    private function parseDescriptors(string $buffer): array
    {
        $reader      = new BinaryReader($buffer);
        $descriptors = [];

        while (!$reader->eof()) {
            $tag     = $reader->uint8();
            $length  = $reader->uint8();
            $payload = $length > 0 ? $reader->bytes($length) : '';

            $descriptor = DescriptorFactory::create($tag, $payload);
            if ($descriptor === null) {
                continue;
            }

            $descriptors[$descriptor->getName()] = $descriptor;
        }

        return $descriptors;
    }

    private function extractTitle(string $titleBytes): string
    {
        if ($titleBytes === '') {
            return '';
        }

        $mss     = new MultipleStringStructure($titleBytes);
        $strings = $mss->getStrings();
        if ($strings === []) {
            return '';
        }

        return $strings['eng'] ?? (string) reset($strings);
    }

    public function getSourceId(): int
    {
        return $this->sourceId;
    }

    public function getVersionNumber(): int
    {
        return $this->versionNumber;
    }

    public function getSectionNumber(): int
    {
        return $this->sectionNumber;
    }

    public function getLastSectionNumber(): int
    {
        return $this->lastSectionNumber;
    }

    /** @return Event[] */
    public function getEvents(): array
    {
        return $this->events;
    }
}
