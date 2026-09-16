<?php declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave;

use Skywave\Io\BinaryReader;

/**
 * ISO/IEC 13818-1 §2.4.4.3 - Program Association Table.
 *
 * Always carried on PID 0x0000 with table_id 0x00. Lists the
 * transport stream's programs by mapping program_number to the
 * PID where the program's PMT will be found. program_number = 0
 * indicates the network_PID instead.
 */
class ProgramAssociationTable extends SectionTable
{
    private int $transportStreamId = 0;
    private int $versionNumber     = 0;
    private ?int $networkPid       = null;

    /** @var array<int, int> program_number => PMT PID */
    private array $programs = [];

    public function parse(): void
    {
        $reader = new BinaryReader($this->buffer);

        $this->transportStreamId = $reader->uint16();
        $reader->skipBits(2); // reserved
        $this->versionNumber = $reader->bits(5);
        $reader->skipBits(1); // current_next_indicator
        $reader->skipBytes(1); // section_number
        $reader->skipBytes(1); // last_section_number

        // Body after prefix and before CRC_32 contains 4-byte entries.
        // Prefix already consumed: tsid(2) + flags(1) + sect#(1) + last#(1) = 5 bytes.
        $entryBytes = $this->sectionLength - 5 - 4;
        $entries    = intdiv($entryBytes, 4);

        for ($i = 0; $i < $entries; $i++) {
            $programNumber = $reader->uint16();
            $reader->skipBits(3); // reserved
            $pid = $reader->bits(13);

            if ($programNumber === 0) {
                $this->networkPid = $pid;
            } else {
                $this->programs[$programNumber] = $pid;
            }
        }
    }

    public function getTransportStreamId(): int
    {
        return $this->transportStreamId;
    }

    public function getVersionNumber(): int
    {
        return $this->versionNumber;
    }

    public function getNetworkPid(): ?int
    {
        return $this->networkPid;
    }

    /** @return array<int, int> program_number => PMT PID */
    public function getPrograms(): array
    {
        return $this->programs;
    }
}
