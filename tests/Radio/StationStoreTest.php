<?php

namespace Skywave\Tests\Radio;

use PHPUnit\Framework\TestCase;
use Skywave\Radio\StationStore;

/**
 * The list of stations that have been heard: the only station list there is, since nothing
 * on the band announces what else is on it.
 */
class StationStoreTest extends TestCase
{
    private string $directory;

    private StationStore $store;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/skywave-stations-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
        $this->store = new StationStore($this->directory . '/guide.sqlite');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->directory);
    }

    public function testStationsComeBackUpTheDial(): void
    {
        $this->store->save(101.1, 'KROX');
        $this->store->save(90.5, 'KUT', [['number' => 0, 'name' => null, 'type' => 'News']], 12.4);

        $stations = $this->store->all();

        $this->assertSame([90.5, 101.1], array_column($stations, 'frequency'));
        $this->assertSame('KUT', $stations[0]['name']);
        $this->assertSame([['number' => 0, 'name' => null, 'type' => 'News']], $stations[0]['programs']);
        $this->assertSame(12.4, $stations[0]['signal']);
    }

    public function testOneFrequencyIsOneStationHoweverItIsSpelled(): void
    {
        $this->store->save(90.5, 'KUT');
        $this->store->save(90.50000001, 'KUT');

        $this->assertCount(1, $this->store->all());
    }

    public function testWhatIsAlreadyKnownSurvivesAReportThatSaysLess(): void
    {
        $programs = [['number' => 0, 'name' => null, 'type' => 'News'], ['number' => 1, 'name' => null, 'type' => 'Public']];

        $this->store->save(90.5, 'KUT', $programs);
        // A station is found before it gives its name, and a weak moment may show one
        // program of two.
        $this->store->save(90.5, null, [$programs[0]]);

        $station = $this->store->find(90.5);

        $this->assertSame('KUT', $station['name']);
        $this->assertSame($programs, $station['programs']);
    }

    public function testHearingTheSameStationAgainWritesNothing(): void
    {
        // Asked every couple of seconds for as long as somebody listens.
        $this->assertTrue($this->store->save(90.5, 'KUT'));
        $this->assertFalse($this->store->save(90.5, 'KUT'));
        $this->assertFalse($this->store->save(90.5, null));
        $this->assertTrue($this->store->save(90.5, 'KUTX'));
    }

    public function testAStationCanBeForgotten(): void
    {
        $this->store->save(90.5, 'KUT');

        $this->assertTrue($this->store->remove(90.5));
        $this->assertFalse($this->store->remove(90.5));
        $this->assertSame([], $this->store->all());
    }
}
