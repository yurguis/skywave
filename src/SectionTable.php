<?php declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave;

/**
 * Shared section-accumulation logic for PSIP tables that may span
 * multiple TS packets. Subclasses implement parse() to interpret
 * the assembled buffer.
 *
 * `sectionLength` is the byte count *after* the section_length field
 * itself (i.e. table_id_extension + ... + CRC_32). The buffer fed
 * into the constructor starts at table_id_extension.
 */
abstract class SectionTable
{
    protected string $buffer;

    protected int $sectionLength;

    protected int $continuityCounter;

    public function __construct(int $sectionLength, int $continuityCounter, string $buffer)
    {
        $this->sectionLength     = $sectionLength;
        $this->continuityCounter = $continuityCounter;
        $this->buffer            = $buffer;
    }

    public function appendToBuffer(string $chunk): void
    {
        if ($this->isBufferComplete()) {
            return;
        }

        $needed        = $this->sectionLength - strlen($this->buffer);
        $this->buffer .= substr($chunk, 0, $needed);

        // Caller has already verified that this chunk's CC == getNextSection(),
        // so advance our tracker to reflect the latest packet absorbed; otherwise
        // the next continuation packet would always read as a CC discontinuity.
        $this->continuityCounter = ($this->continuityCounter + 1) & 0x0F;
    }

    public function isBufferComplete(): bool
    {
        return strlen($this->buffer) >= $this->sectionLength;
    }

    public function getNextSection(): int
    {
        return ($this->continuityCounter + 1) & 0x0F;
    }

    abstract public function parse(): void;
}
