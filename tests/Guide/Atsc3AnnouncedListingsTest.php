<?php

namespace Skywave\Tests\Guide;

use PHPUnit\Framework\TestCase;
use Skywave\Guide\GuideStore;

/**
 * An ATSC 3.0 service can announce what it is showing, and that is what it shows.
 *
 * These stations have no PSIP tables, so until now the only listings they could carry were
 * borrowed from the channel they simulcast. An encrypted station whose own signalling
 * cannot be read still announces its programmes separately, in the clear, and those
 * listings describe it properly rather than by proxy.
 *
 * The borrowing is not replaced: it remains what a station without an announcement falls
 * back to.
 */
class Atsc3AnnouncedListingsTest extends TestCase
{
    private const DEVICE = '192.168.1.62';

    private string $directory;

    private GuideStore $store;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/skywave-atsc3-esg-' . bin2hex(random_bytes(6));
        mkdir($this->directory);

        $this->store = new GuideStore($this->directory . '/guide.sqlite');
        $this->store->saveLineup(self::DEVICE, [
            ['physical' => 29, 'program' => 3, 'virtual' => '6.1', 'name' => 'WTVJ', 'tsid' => 627, 'encrypted' => false, 'hd' => true],
        ]);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->directory));
    }

    public function testAStationShowsTheProgrammesItAnnounces(): void
    {
        $this->listStation('106.1', 'WTVJ-DT');
        $this->announce('106.1', 'El Señor de los Cielos');

        $events = $this->guideChannel('106.1')['events'];

        $this->assertCount(1, $events);
        $this->assertSame('El Señor de los Cielos', $events[0]['title']);
    }

    public function testAnAnnouncementCarriesTheDetailTheGuideShows(): void
    {
        $this->listStation('106.1', 'WTVJ-DT');
        $this->announce('106.1', 'The Voice', rating: 'TV-PG', description: 'Blind auditions continue.');

        $event = $this->guideChannel('106.1')['events'][0];

        $this->assertSame('TV-PG', $event['rating']);
        $this->assertSame('Blind auditions continue.', $event['description']);
        $this->assertSame(1800, $event['duration']);
    }

    public function testWhatAStationAnnouncesBeatsWhatItWouldBorrow(): void
    {
        // 6.1 is the channel 106.1 simulcasts, so without an announcement its listings
        // would stand in. The station describing itself is the better answer.
        $this->addCounterpartEvent('Pro Motocross Championship');
        $this->listStation('106.1', 'WTVJ-DT');
        $this->announce('106.1', 'El Señor de los Cielos');

        $titles = array_column($this->guideChannel('106.1')['events'], 'title');

        $this->assertSame(['El Señor de los Cielos'], $titles);
    }

    public function testAStationThatAnnouncesNothingStillBorrows(): void
    {
        $this->addCounterpartEvent('Pro Motocross Championship');
        $this->listStation('106.1', 'WTVJ-DT');

        $titles = array_column($this->guideChannel('106.1')['events'], 'title');

        $this->assertSame(['Pro Motocross Championship'], $titles);
        $this->assertSame($this->guideChannel('6.1')['events'], $this->guideChannel('106.1')['events']);
    }

    public function testAnnouncementsBelongToOneStationOnly(): void
    {
        $this->listStation('106.1', 'WTVJ-DT');
        $this->listStation('123.1', 'WLTV-DT');
        $this->announce('106.1', 'El Señor de los Cielos');

        $this->assertSame([], $this->guideChannel('123.1')['events']);
    }

    public function testOnlyTheProgrammesInTheWindowAreShown(): void
    {
        $this->listStation('106.1', 'WTVJ-DT');
        $this->announce('106.1', 'Tonight', start: time() + 600);
        $this->announce('106.1', 'Tomorrow', start: time() + 86_400);

        $titles = array_column($this->guideChannel('106.1')['events'], 'title');

        $this->assertSame(['Tonight'], $titles);
    }

    public function testAnnouncingAgainRefreshesRatherThanDuplicates(): void
    {
        // A reading that recovers the same showing again should correct it, not add it
        // twice: the announcement is carried over and over, not sent once.
        $this->listStation('106.1', 'WTVJ-DT');
        $start = time() + 600;
        $this->announce('106.1', 'Working Title', start: $start);
        $this->announce('106.1', 'Corrected Title', start: $start);

        $titles = array_column($this->guideChannel('106.1')['events'], 'title');

        $this->assertSame(['Corrected Title'], $titles);
    }

    public function testAnnouncementsStayOutOfTheLineupThatDrivesTuning(): void
    {
        // The whole reason these are stored apart: nothing here can be tuned, and the
        // collector must never be handed one of these as a channel to point a tuner at.
        $this->listStation('106.1', 'WTVJ-DT');
        $this->announce('106.1', 'El Señor de los Cielos');

        $lineup = $this->store->getLineup(self::DEVICE);

        $this->assertCount(1, $lineup);
        $this->assertSame('6.1', $lineup[0]['virtual']);
        $this->assertNotContains('El Señor de los Cielos', $this->store->eventTitles(self::DEVICE));
    }

    public function testAnnouncementsSurviveTheLineupBeingSavedAgain(): void
    {
        // The lineup is re-saved every few hours. Listings read separately must not be
        // collateral damage.
        $this->listStation('106.1', 'WTVJ-DT');
        $this->announce('106.1', 'El Señor de los Cielos');
        $this->listStation('106.1', 'WTVJ-DT');

        $this->assertCount(1, $this->guideChannel('106.1')['events']);
    }

    private function listStation(string $virtual, string $name): void
    {
        $this->store->saveAtsc3Lineup(self::DEVICE, [[
            'virtual'    => $virtual,
            'name'       => $name,
            'videoCodec' => 'HEVC',
            'audioCodec' => 'AC4',
            'drm'        => true,
            'broadband'  => false,
            'streamUrl'  => null,
            'hd'         => true,
        ]], GuideStore::ATSC3_SOURCE_SLT);
    }

    private function announce(
        string $virtual,
        string $title,
        ?int $start = null,
        ?string $rating = null,
        ?string $description = null
    ): void {
        $this->store->saveAtsc3Events(self::DEVICE, [[
            'virtual'     => $virtual,
            'eventId'     => 15_024,
            'start'       => $start ?? time() + 600,
            'duration'    => 1800,
            'title'       => $title,
            'rating'      => $rating,
            'description' => $description,
        ]]);
    }

    /**
     * An event on 6.1, the channel the 106.1 service simulcasts.
     */
    private function addCounterpartEvent(string $title): void
    {
        $db = new \PDO('sqlite:' . $this->directory . '/guide.sqlite', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);

        $find = $db->prepare('SELECT id FROM channels WHERE device = ? AND physical = ? AND program = ?');
        $find->execute([self::DEVICE, 29, 3]);

        $db->prepare(
            'INSERT OR REPLACE INTO events (channel_id, event_id, start, duration, title, rating, description, updated_at)
             VALUES (?, ?, ?, ?, ?, NULL, NULL, ?)'
        )->execute([(int) $find->fetchColumn(), 42, time() + 600, 1800, $title, time()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function guideChannel(string $virtual): array
    {
        foreach ($this->store->getGuide(time() - 60, time() + 3600, self::DEVICE) as $channel) {
            if ($channel['virtual'] === $virtual) {
                return $channel;
            }
        }

        $this->fail("No channel $virtual in the guide");
    }
}
