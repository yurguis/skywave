<?php

declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave\Structure;

use Skywave\Io\BinaryReader;

/**
 * Multiple String Structure
 * see 6.10 https://prdatsc.wpenginepowered.com/wp-content/uploads/2021/04/A65_2013.pdf
 */
class MultipleStringStructure
{
    public const COMPRESSION_TYPES = [
        0 => 'No compression',
        1 => 'Huffman C (4, 5)',
        2 => 'Huffman C (6, 7)',
    ];

    protected array $strings = [];

    public function __construct(string $data)
    {
        $reader = new BinaryReader($data);

        $numberStrings = $reader->uint8();

        for ($i = 0; $i < $numberStrings; $i++) {
            $language       = $reader->bytes(3);
            $numberSegments = $reader->uint8();

            for ($j = 0; $j < $numberSegments; $j++) {
                $compressionType = $reader->uint8();
                $mode            = $reader->uint8();
                $numberBytes     = $reader->uint8();
                $segment         = $reader->bytes($numberBytes);

                // Compressed segments and other character modes are not decoded here, but
                // their bytes are still read: skipping them without consuming the segment
                // leaves every string after this one being read from the wrong offset.
                if ($compressionType !== 0 || $mode !== 0) {
                    continue;
                }

                // Mode 0x00 means each byte is a code point between 0x00 and 0x00FF, which
                // is how a broadcast sends "Cafe\xE9". Handed on as-is those bytes are not
                // valid UTF-8, and the page shows a replacement character instead of the
                // accent. A string may also arrive in several segments, which join up.
                $this->strings[$language] = ($this->strings[$language] ?? '')
                    . mb_convert_encoding($segment, 'UTF-8', 'ISO-8859-1');
            }
        }
    }

    public function getStrings(): array
    {
        return $this->strings;
    }
}
