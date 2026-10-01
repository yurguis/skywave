<?php

namespace Skywave\Tests\Radio;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Skywave\Radio\Scanner;

/**
 * Trying frequencies, with PHP standing in for nrsc5: one that finds a station and says
 * so, one that finds nothing and says nothing, and one that cannot listen at all.
 */
class ScannerTest extends TestCase
{
    private const STATION = 'fwrite(STDERR, "14:31:16 Synchronized\n14:31:16 Station name: KUT \n'
        . '14:31:17 Audio service 0: public, type: News, codec: 0, blend: 2, gain: 0 dB, delay: 96, latency: 8\n'
        . '14:31:17 MER: 12.9 dB (lower), 12.2 dB (upper)\n"); sleep(30);';

    public function testTheDialIsTheOddTenths(): void
    {
        $frequencies = Scanner::frequencies();

        $this->assertCount(101, $frequencies);
        $this->assertSame(87.9, $frequencies[0]);
        $this->assertSame(107.9, $frequencies[100]);
        // Counted in steps: adding 0.2 a hundred times drifts off the tenth.
        $this->assertSame(90.5, $frequencies[13]);
    }

    public function testAScanCanBeNarrowed(): void
    {
        $this->assertSame([90.1, 90.3, 90.5], Scanner::frequencies(90.0, 90.5));
        $this->assertSame([], Scanner::frequencies(90.2, 90.2));
    }

    public function testAStationIsReportedWithWhatItSaidOfItself(): void
    {
        $started = microtime(true);
        $station = $this->scanner(self::STATION, 5.0, 0.4)->probe(90.5);

        $this->assertSame(90.5, $station['frequency']);
        $this->assertSame('KUT', $station['name']);
        $this->assertSame([['number' => 0, 'name' => null, 'type' => 'News']], $station['programs']);
        $this->assertSame(12.2, $station['signal']);
        // It did not wait out the search, nor for the stand-in to finish sleeping.
        $this->assertLessThan(4.0, microtime(true) - $started);
    }

    public function testAnEmptyFrequencyCostsTheWaitAndNoMore(): void
    {
        $started = microtime(true);

        $this->assertNull($this->scanner('sleep(30);', 0.5)->probe(88.1));
        $this->assertLessThan(4.0, microtime(true) - $started);
    }

    public function testADongleThatCannotBeOpenedEndsTheScanWithTheReason(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No RTL-SDR dongle was found');

        $this->scanner('fwrite(STDERR, "14:31:16 Open device failed.\n"); exit(1);', 2.0)->probe(88.1);
    }

    public function testEveryFrequencyIsReportedFoundOrNot(): void
    {
        $results = [];

        $this->scanner(self::STATION, 5.0, 0.2)->scan(
            [90.3, 90.5],
            static function (int $index, float $frequency, ?array $station) use (&$results): void {
                $results[] = [$index, $frequency, $station['name'] ?? null];
            }
        );

        $this->assertSame([[0, 90.3, 'KUT'], [1, 90.5, 'KUT']], $results);
    }

    public function testAScanAskedToStopTriesNothingMore(): void
    {
        $tried = 0;

        $this->scanner('sleep(30);', 0.3)->scan(
            [88.1, 88.3, 88.5],
            static function () use (&$tried): void {
                $tried++;
            },
            static function () use (&$tried): bool {
                return $tried >= 1;
            }
        );

        $this->assertSame(1, $tried);
    }

    private function scanner(string $standIn, float $syncSeconds, float $detailSeconds = 0.2): Scanner
    {
        return new Scanner(static fn (float $frequency): array => [PHP_BINARY, '-r', $standIn], $syncSeconds, $detailSeconds);
    }
}
