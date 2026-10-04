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

    public function testAnAmStationKeepsItsKilohertz(): void
    {
        $store = $this->store;
        $store->save(1.14, 'WQBA', [], null, 'am');

        // Rounded to a tenth of a MHz, as FM is, 1140 kHz would come back as 1100.
        $this->assertSame(1.14, $store->find(1.14)['frequency']);
        $this->assertSame('am', $store->find(1.14)['mode']);
    }

    public function testTheTwoBandsShareTheColumnWithoutColliding(): void
    {
        $store = $this->store;
        $store->save(1.14, 'WQBA', [], null, 'am');
        $store->save(93.1, 'WFEZ', [], null, 'fm');

        $all = $store->all();

        $this->assertCount(2, $all);
        $this->assertSame([1.14, 93.1], array_column($all, 'frequency'), 'up the dial, AM first');
        $this->assertSame(['am', 'fm'], array_column($all, 'mode'));
    }

    public function testKnowingAStationHasHdRadioIsNotForgotten(): void
    {
        $store = $this->store;
        $store->save(93.1, 'WFEZ', [['number' => 0, 'name' => 'HD1', 'type' => null]]);

        // Listening to the analog underneath does not mean the HD went away.
        $store->save(93.1, 'WFEZ', [], null, 'fm');

        $this->assertSame('hd', $store->find(93.1)['mode']);
    }

    public function testAnAnalogStationStaysAnalogUntilHdIsFound(): void
    {
        $store = $this->store;
        $store->save(104.3, null, [], null, 'fm');

        $this->assertSame('fm', $store->find(104.3)['mode']);

        $store->save(104.3, 'WQAM', [], null, 'hd');

        $this->assertSame('hd', $store->find(104.3)['mode']);
    }

    public function testADatabaseMadeBeforeAnalogGainsTheColumn(): void
    {
        // What the table looked like when nrsc5 was the only way to hear anything.
        $path = $this->directory . '/legacy.sqlite';
        $db   = new \PDO('sqlite:' . $path);
        $db->exec(
            'CREATE TABLE radio_stations (
                frequency INTEGER PRIMARY KEY, name TEXT,
                programs TEXT NOT NULL DEFAULT \'[]\', signal REAL, heard_at INTEGER NOT NULL)'
        );
        $db->exec("INSERT INTO radio_stations VALUES (93100, 'WFEZ', '[]', NULL, 1)");
        $db = null;

        $store = new StationStore($path);

        $this->assertSame('hd', $store->find(93.1)['mode'], 'everything already there was found by nrsc5');
    }
}
