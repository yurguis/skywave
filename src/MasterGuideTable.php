<?php declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave;

use Skywave\Io\BinaryReader;

/**
 * ATSC A/65 §6.2 - Master Guide Table.
 *
 * Describes which PIDs carry each of the other PSIP tables
 * (EIT-0..127, ETT-0..127, RRT, DCCT, etc.).
 */
class MasterGuideTable extends SectionTable
{
    private int $tablesDefined = 0;

    /** @var TableEntry[] */
    private array $entries = [];

    public function parse(): void
    {
        $reader = new BinaryReader($this->buffer);

        // table_id_extension (0x0000 for MGT)
        $reader->skipBytes(2);
        // reserved(2) + version_number(5) + current_next_indicator(1)
        // + section_number(8) + last_section_number(8) + protocol_version(8)
        $reader->skipBytes(4);

        $this->tablesDefined = $reader->uint16();

        for ($i = 0; $i < $this->tablesDefined; $i++) {
            $tableType = $reader->uint16();

            $reader->skipBits(3); // reserved
            $pid = $reader->bits(13);

            $reader->skipBits(3); // reserved
            $versionNumber = $reader->bits(5);

            $numberBytes = $reader->uint32();

            $reader->skipBits(4); // reserved
            $descriptorsLength = $reader->bits(12);

            $descriptors = $descriptorsLength > 0
                ? $reader->bytes($descriptorsLength)
                : '';

            $this->entries[] = new TableEntry(
                $tableType,
                $pid,
                $versionNumber,
                $numberBytes,
                $descriptors
            );
        }

        // Outer descriptors loop (after all entries) is intentionally not
        // surfaced yet - none of the descriptors we recognize today appear
        // there. Skipped along with the CRC_32 trailer.
    }

    public function getTablesDefined(): int
    {
        return $this->tablesDefined;
    }

    /** @return TableEntry[] sorted by table_type ascending */
    public function getEntries(): array
    {
        if (count($this->entries) < 2) {
            return $this->entries;
        }

        $sorted = $this->entries;
        usort($sorted, static function (TableEntry $a, TableEntry $b) {
            return $a->getTableType() <=> $b->getTableType();
        });

        return $sorted;
    }
}
