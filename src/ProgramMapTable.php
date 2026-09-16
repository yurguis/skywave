<?php declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave;

use Skywave\Descriptor\DescriptorFactory;
use Skywave\Io\BinaryReader;

/**
 * ISO/IEC 13818-1 §2.4.4.8 - Program Map Table.
 *
 * One PMT section per program, carried on the PID assigned by the PAT.
 * Lists the program's PCR PID and its elementary streams (video,
 * audio, captions, data) by stream_type and elementary PID.
 *
 * Program-level descriptors and per-stream (ES) descriptors are
 * skipped here for now; can be wired in via DescriptorFactory later.
 */
class ProgramMapTable extends SectionTable
{
    private int $programNumber = 0;
    private int $versionNumber = 0;
    private int $pcrPid        = 0;

    /** @var object[] keyed by descriptor name */
    private array $programDescriptors = [];

    /** @var Stream[] */
    private array $streams = [];

    public function parse(): void
    {
        $reader = new BinaryReader($this->buffer);

        $this->programNumber = $reader->uint16();
        $reader->skipBits(2); // reserved
        $this->versionNumber = $reader->bits(5);
        $reader->skipBits(1); // current_next_indicator
        $reader->skipBytes(1); // section_number  (always 0 for PMT)
        $reader->skipBytes(1); // last_section_number (always 0)

        $reader->skipBits(3); // reserved
        $this->pcrPid = $reader->bits(13);

        $reader->skipBits(4); // reserved
        $programInfoLength = $reader->bits(12);
        if ($programInfoLength > 0) {
            $this->programDescriptors = $this->parseDescriptors($reader->bytes($programInfoLength));
        }

        // Walk ES entries until we reach the 4-byte CRC at the end of the section.
        $endOfStreamsBit = ($this->sectionLength - 4) * 8;

        while ($reader->bitPosition() < $endOfStreamsBit) {
            $streamType = $reader->uint8();
            $reader->skipBits(3); // reserved
            $pid = $reader->bits(13);

            $reader->skipBits(4); // reserved
            $esInfoLength = $reader->bits(12);
            $descriptors  = [];
            if ($esInfoLength > 0) {
                $descriptors = $this->parseDescriptors($reader->bytes($esInfoLength));
            }

            $this->streams[] = new Stream($streamType, $pid, null, $descriptors);
        }
    }

    public function getProgramNumber(): int
    {
        return $this->programNumber;
    }

    public function getVersionNumber(): int
    {
        return $this->versionNumber;
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

    /** @return object[] keyed by descriptor name */
    public function getProgramDescriptors(): array
    {
        return $this->programDescriptors;
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
}
