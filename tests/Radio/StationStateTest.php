<?php

namespace Skywave\Tests\Radio;

use PHPUnit\Framework\TestCase;
use Skywave\Radio\StationState;

/**
 * Reading a station out of what nrsc5 prints.
 *
 * The lines for the station itself are the ones nrsc5 printed for its own sample capture.
 * The picture lines are written from nrsc5's source, which is the only place their format is
 * set down: the sample carries no pictures, so none of that has been seen from a broadcast.
 */
class StationStateTest extends TestCase
{
    public function testAStationIsReadFromWhatNrsc5Prints(): void
    {
        $state = $this->heard([
            '14:31:16 Best gain: 49.6 dB, Peak amplitude: -11.8 dBFS',
            '14:31:16 Synchronized',
            '14:31:16 Frequency offset: 97 Hz',
            '14:31:16 Station name: KUT ',
            '14:31:16 Country: AB, FCC facility ID: 21',
            '14:31:18 Slogan: The University of Texas at Austin',
            "14:31:19 Title: You're Listening to Q with Jian Ghomeshi",
            '14:31:19 Artist:  ',
            '14:31:19 Audio bit rate: 63.7 kbps',
            '14:31:19 MER: 12.9 dB (lower), 12.2 dB (upper)',
            '14:31:19 BER: 0.000138, avg: 0.000201, min: 0.000000, max: 0.001302',
        ]);

        $station = $state->toArray();

        $this->assertTrue($station['synchronized']);
        $this->assertSame('KUT', $station['station']);
        $this->assertSame('The University of Texas at Austin', $station['slogan']);
        $this->assertSame("You're Listening to Q with Jian Ghomeshi", $station['title']);
        // Sent as two spaces, which is a station with nothing to say rather than an artist.
        $this->assertNull($station['artist']);
        $this->assertSame(63.7, $station['bitrate']);
        $this->assertSame(49.6, $station['gain']);
        // The weaker sideband: it is the one that decides whether the sound holds.
        $this->assertSame(12.2, $station['mer']);
        $this->assertSame(0.000201, $station['ber']);
    }

    public function testEveryProgramTheStationCarriesIsListed(): void
    {
        $state = $this->heard([
            '14:31:19 Audio service 1: public, type: Public, codec: 0, blend: 0, gain: 0 dB, delay: 0, latency: 8',
            '14:31:19 Audio service 0: public, type: None, codec: 0, blend: 2, gain: 0 dB, delay: 96, latency: 8',
            '14:31:20 Audio program 0: public, type: News, sound experience 0',
        ]);

        $this->assertSame([
            ['number' => 0, 'name' => null, 'type' => 'News'],
            ['number' => 1, 'name' => null, 'type' => 'Public'],
        ], $state->toArray()['programs']);
    }

    public function testOnlyAChangeIsWorthTelling(): void
    {
        $state = new StationState();

        $this->assertTrue($state->apply('14:31:19 Title: Morning Edition'));
        $this->assertFalse($state->apply('14:31:20 Title: Morning Edition'));
        $this->assertFalse($state->apply('14:31:20 Frequency offset: 97 Hz'));
        $this->assertFalse($state->apply('not a log line at all'));
    }

    public function testLosingTheStationKeepsWhatWasKnownOfIt(): void
    {
        $state = $this->heard([
            '14:31:16 Synchronized',
            '14:31:16 Station name: KUT',
            '14:31:30 Lost synchronization',
        ]);

        $this->assertFalse($state->isSynchronized());
        $this->assertSame('KUT', $state->toArray()['station']);
    }

    public function testWhyNrsc5GaveUpIsKept(): void
    {
        $state = $this->heard(['14:31:16 Connection failed.']);

        $this->assertSame('Connection failed.', $state->getError());
        $this->assertSame('Open device failed.', $this->heard(['14:31:16 Open device failed.'])->getError());
        $this->assertNull($this->heard(['14:31:16 Synchronized'])->getError());
    }

    public function testASongsCoverIsTheOneItAsksFor(): void
    {
        $state = $this->heard([
            '14:31:20 SIG Service: type=audio number=1 name=MPS',
            '14:31:20   Audio component: id=0 port=0001 type=0 mime=4B95C5EF',
            '14:31:20   Data component: id=1 port=0810 service_data_type=74 type=3 mime=BE4B7536',
            '14:31:20   Data component: id=2 port=0811 service_data_type=65 type=3 mime=D9C72536',
            '14:31:25 LOT file: port=0811 lot=7 name=SLKUT$$010001.png size=5021 mime=D9C72536 expiry=2026-10-02T00:00:00Z',
            '14:31:30 LOT file: port=0810 lot=4242 name=cover.jpg size=18400 mime=BE4B7536 expiry=2026-10-01T19:00:00Z',
        ]);

        // Sent, but no song has asked for it yet.
        $this->assertNull($state->toArray()['art']);
        // A name with characters outside the plain ones is not served, whoever sent it.
        $this->assertNull($state->toArray()['logo']);
        $this->assertSame('MPS', $state->toArray()['programs'][0]['name']);

        $state->apply('14:31:31 XHDR: 0 BE4B7536 4242');
        $this->assertSame('4242_cover.jpg', $state->toArray()['art']);

        // "1" is the song saying it has no cover and the logo should stand in.
        $state->apply('14:35:02 XHDR: 1 BE4B7536 -1');
        $this->assertNull($state->toArray()['art']);
    }

    public function testAnotherProgramsPicturesAreNotThisOnes(): void
    {
        $state = $this->heard([
            '14:31:20 SIG Service: type=audio number=1 name=MPS',
            '14:31:20   Data component: id=2 port=0811 service_data_type=65 type=3 mime=D9C72536',
            '14:31:20 SIG Service: type=audio number=2 name=SPS1',
            '14:31:20   Data component: id=2 port=0821 service_data_type=65 type=3 mime=D9C72536',
            '14:31:25 LOT file: port=0821 lot=9 name=hd2-logo.png size=5021 mime=D9C72536 expiry=2026-10-02T00:00:00Z',
        ]);

        $this->assertNull($state->toArray()['logo']);

        $state->apply('14:31:26 LOT file: port=0811 lot=7 name=hd1-logo.png size=5021 mime=D9C72536 expiry=2026-10-02T00:00:00Z');
        $this->assertSame('7_hd1-logo.png', $state->toArray()['logo']);
    }

    public function testAFileThatIsNotAPictureIsNotOffered(): void
    {
        $state = $this->heard([
            '14:31:25 LOT file: port=0811 lot=7 name=TMT_03_01.txt size=120 mime=D9C72536 expiry=2026-10-02T00:00:00Z',
            '14:31:25 LOT file: port=0811 lot=8 name=../../session.jpg size=120 mime=D9C72536 expiry=2026-10-02T00:00:00Z',
        ]);

        $this->assertNull($state->toArray()['logo']);
    }

    /**
     * @param string[] $lines
     */
    private function heard(array $lines, int $program = 0): StationState
    {
        $state = new StationState($program);

        foreach ($lines as $line) {
            $state->apply($line . "\n");
        }

        return $state;
    }
}
