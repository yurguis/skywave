<?php

declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave\Io;

use InvalidArgumentException;
use OutOfBoundsException;
use RuntimeException;

/**
 * Forward-only bit/byte reader for MPEG-TS payloads.
 *
 * Reads are MSB-first to match the `uimsbf` convention used by the
 * ATSC A/65 and ISO 13818-1 specifications: when a field straddles
 * a byte boundary, the most significant bits come from the earlier byte.
 */
class BinaryReader
{
    private string $data;
    private int $bitPos = 0;
    private int $totalBits;

    public function __construct(string $data)
    {
        $this->data      = $data;
        $this->totalBits = strlen($data) * 8;
    }

    /**
     * Read $n bits (1..32) as an unsigned integer, MSB-first.
     */
    public function bits(int $n): int
    {
        if ($n < 1 || $n > 32) {
            throw new InvalidArgumentException("bits(\$n): \$n must be between 1 and 32, got $n");
        }

        if ($this->bitPos + $n > $this->totalBits) {
            throw new OutOfBoundsException("Read of $n bits past end of buffer (pos=$this->bitPos, total=$this->totalBits)");
        }

        $value = 0;
        for ($i = 0; $i < $n; $i++) {
            $byteIdx   = $this->bitPos >> 3;
            $bitInByte = 7 - ($this->bitPos & 7);
            $bit       = (ord($this->data[$byteIdx]) >> $bitInByte) & 1;
            $value     = ($value << 1) | $bit;
            $this->bitPos++;
        }

        return $value;
    }

    public function uint8(): int
    {
        return $this->bits(8);
    }

    public function uint16(): int
    {
        return $this->bits(16);
    }

    public function uint32(): int
    {
        return $this->bits(32);
    }

    /**
     * Read $n raw bytes. Requires byte alignment.
     */
    public function bytes(int $n): string
    {
        if (($this->bitPos & 7) !== 0) {
            throw new RuntimeException("bytes() requires byte alignment, current bit position is $this->bitPos");
        }

        if ($this->bitPos + $n * 8 > $this->totalBits) {
            throw new OutOfBoundsException("Read of $n bytes past end of buffer");
        }

        $result = substr($this->data, $this->bitPos >> 3, $n);
        $this->bitPos += $n * 8;

        return $result;
    }

    public function skipBits(int $n): void
    {
        if ($this->bitPos + $n > $this->totalBits) {
            throw new OutOfBoundsException("Skip of $n bits past end of buffer");
        }

        $this->bitPos += $n;
    }

    public function skipBytes(int $n): void
    {
        $this->skipBits($n * 8);
    }

    public function eof(): bool
    {
        return $this->bitPos >= $this->totalBits;
    }

    public function remainingBytes(): int
    {
        return intdiv($this->totalBits - $this->bitPos, 8);
    }

    public function bitPosition(): int
    {
        return $this->bitPos;
    }
}
