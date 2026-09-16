<?php declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave\Avc;

use OutOfBoundsException;

/**
 * MSB-first bit reader with Exp-Golomb support. Built for H.264 SPS parsing
 * where most syntax elements are variable-length ue(v) or se(v) codes.
 */
class BitReader
{
    private string $data;
    private int $bitPos = 0;
    private int $totalBits;

    public function __construct(string $data)
    {
        $this->data      = $data;
        $this->totalBits = strlen($data) * 8;
    }

    public function bit(): int
    {
        if ($this->bitPos >= $this->totalBits) {
            throw new OutOfBoundsException('Read past end of bitstream');
        }

        $byte = ord($this->data[$this->bitPos >> 3]);
        $bit  = ($byte >> (7 - ($this->bitPos & 7))) & 1;
        $this->bitPos++;

        return $bit;
    }

    public function bits(int $n): int
    {
        $value = 0;

        for ($i = 0; $i < $n; $i++) {
            $value = ($value << 1) | $this->bit();
        }

        return $value;
    }

    /** Unsigned Exp-Golomb (ue(v)). */
    public function ue(): int
    {
        $leadingZeros = 0;

        while ($leadingZeros < 32 && $this->bit() === 0) {
            $leadingZeros++;
        }

        if ($leadingZeros === 0) {
            return 0;
        }

        return (1 << $leadingZeros) + $this->bits($leadingZeros) - 1;
    }

    /** Signed Exp-Golomb (se(v)). */
    public function se(): int
    {
        $code = $this->ue();

        if ($code === 0) {
            return 0;
        }

        return ($code & 1) === 1 ? intdiv($code + 1, 2) : -intdiv($code, 2);
    }
}
