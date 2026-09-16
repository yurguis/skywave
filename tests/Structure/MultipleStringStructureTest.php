<?php

namespace Skywave\Tests\Structure;

use PHPUnit\Framework\TestCase;
use Skywave\Structure\MultipleStringStructure;

/**
 * Channel names and programme titles arrive in this structure. A/65 sends them as Latin-1,
 * so anything with an accent depends on the conversion below being right.
 */
class MultipleStringStructureTest extends TestCase
{
    public function testPlainTextIsReadAsItWasSent(): void
    {
        $structure = new MultipleStringStructure($this->encode([['eng', ['The Price Is Right']]]));

        $this->assertSame(['eng' => 'The Price Is Right'], $structure->getStrings());
    }

    public function testAccentsSurviveAsUtf8(): void
    {
        // "El News Café" as the broadcast sends it: one byte per character, 0xE9 for é.
        $latin1 = "El News Caf\xE9";

        $structure = new MultipleStringStructure($this->encode([['spa', [$latin1]]]));

        $this->assertSame(['spa' => 'El News Café'], $structure->getStrings());
        $this->assertTrue(mb_check_encoding($structure->getStrings()['spa'], 'UTF-8'));
    }

    public function testSpanishPunctuationSurvives(): void
    {
        // ¡Siéntese Quién Pueda! — inverted marks are 0xA1 and 0xBF in Latin-1.
        $latin1 = "\xA1Si\xE9ntese Qui\xE9n Pueda!";

        $structure = new MultipleStringStructure($this->encode([['spa', [$latin1]]]));

        $this->assertSame(['spa' => '¡Siéntese Quién Pueda!'], $structure->getStrings());
    }

    public function testSegmentsOfOneStringAreJoined(): void
    {
        $structure = new MultipleStringStructure($this->encode([['eng', ['Antiques ', 'Roadshow']]]));

        $this->assertSame(['eng' => 'Antiques Roadshow'], $structure->getStrings());
    }

    public function testEachLanguageIsKept(): void
    {
        $structure = new MultipleStringStructure($this->encode([
            ['eng', ['The Simpsons']],
            ['spa', ["Los Simpson\xAE"]],
        ]));

        $this->assertSame(['eng' => 'The Simpsons', 'spa' => 'Los Simpson®'], $structure->getStrings());
    }

    public function testACompressedSegmentDoesNotDerailTheOnesAfterIt(): void
    {
        // Huffman segments are not decoded, but their bytes still have to be consumed or
        // every string after them is read from the wrong offset.
        $data = chr(2)
            . 'eng' . chr(1) . chr(1) . chr(0) . chr(5) . 'xxxxx'
            . 'spa' . chr(1) . chr(0) . chr(0) . chr(4) . 'Hola';

        $structure = new MultipleStringStructure($data);

        $this->assertSame(['spa' => 'Hola'], $structure->getStrings());
    }

    /**
     * Build a multiple string structure the way a broadcast does.
     *
     * @param list<array{0: string, 1: list<string>}> $strings language code and its segments
     */
    private function encode(array $strings): string
    {
        $data = chr(count($strings));

        foreach ($strings as [$language, $segments]) {
            $data .= $language . chr(count($segments));

            foreach ($segments as $segment) {
                // compression_type 0, mode 0, then the bytes.
                $data .= chr(0) . chr(0) . chr(strlen($segment)) . $segment;
            }
        }

        return $data;
    }
}
