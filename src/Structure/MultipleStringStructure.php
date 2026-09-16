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
                if ($compressionType !== 0) {
                    continue;
                }

                $mode = $reader->uint8();
                if ($mode !== 0) {
                    continue;
                }

                $numberBytes              = $reader->uint8();
                $this->strings[$language] = $reader->bytes($numberBytes);
            }
        }
    }

    public function getStrings(): array
    {
        return $this->strings;
    }
}
