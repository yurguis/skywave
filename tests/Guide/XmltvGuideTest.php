<?php

namespace Skywave\Tests\Guide;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Skywave\Guide\GuideStore;
use Skywave\Guide\XmltvGuide;

/**
 * The guide SiliconDust publishes, for the channels that broadcast none.
 *
 * The fixture is a cut of a real answer from their service, kept whole rather than written
 * by hand: it is the only record of how they actually name a channel.
 */
class XmltvGuideTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/skywave-xmltv-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->directory));
    }

    public function testAChannelListedUnderTwoNumbersIsFiledUnderBoth(): void
    {
        // WFOR arrives twice under one identifier, as 4.1 and as 104.1 -- its ATSC 1.0 and
        // 3.0 broadcasts, carrying the same schedule. Keeping one would leave whichever
        // lost with no guide at all, and which one lost would be whichever came last.
        $guide = XmltvGuide::parse((string) file_get_contents(__DIR__ . '/fixtures/xmltv-excerpt.xml'));

        $this->assertArrayHasKey('4.1', $guide);
        $this->assertArrayHasKey('104.1', $guide);
        $this->assertSame($guide['4.1'], $guide['104.1'], 'the same schedule, not half of it each');
    }

    public function testAProgrammeKeepsWhatTheGuideShows(): void
    {
        $guide = XmltvGuide::parse((string) file_get_contents(__DIR__ . '/fixtures/xmltv-excerpt.xml'));
        $first = $guide['69.3'][0];

        $this->assertNotSame('', $first['title']);
        $this->assertGreaterThan(0, $first['duration']);
        $this->assertGreaterThan(1_600_000_000, $first['start'], 'a real moment, not a parse of nothing');
    }

    public function testNothingUsableIsTakenFromRubbish(): void
    {
        $this->assertSame([], XmltvGuide::parse('<tv></tv>'));

        // No title, no start, a stop before its start: each is nothing worth showing.
        $this->assertSame([], XmltvGuide::parse(
            '<tv><channel id="a"><display-name>9.1</display-name></channel>'
            . '<programme channel="a" start="20261010180000 -0400" stop="20261010170000 -0400"><title>Backwards</title></programme>'
            . '<programme channel="a" start="20261010180000 -0400" stop="20261010190000 -0400"><title> </title></programme>'
            . '<programme channel="a" start="rubbish" stop="20261010190000 -0400"><title>Timeless</title></programme></tv>'
        ));
    }

    public function testSomethingThatIsNotXmltvIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        XmltvGuide::parse('<<<not xml at all');
    }

    public function testItIsOffUnlessAskedFor(): void
    {
        putenv('GUIDE_XMLTV');
        $this->assertNull(XmltvGuide::fromEnvironment(), 'the guide works without it, so it is never the default');

        putenv('GUIDE_XMLTV=1');
        $this->assertInstanceOf(XmltvGuide::class, XmltvGuide::fromEnvironment());

        putenv('GUIDE_XMLTV=0');
        $this->assertNull(XmltvGuide::fromEnvironment());
        putenv('GUIDE_XMLTV');
    }

    public function testItFillsOnlyWhereTheAirSaidNothing(): void
    {
        $store = new GuideStore($this->directory . '/guide.sqlite');
        $now   = 1_800_000_000;

        $this->seed($store, 'tuner', [
            ['virtual' => '4.1', 'name' => 'WFOR', 'events' => [
                // One hour of broadcast guide, starting now.
                ['eventId' => 11, 'start' => $now, 'duration' => 3600, 'title' => 'From the air'],
            ]],
            ['virtual' => '69.3', 'name' => 'GREAT', 'events' => []],
        ]);

        $counts = $store->fillGapsFromXmltv('tuner', [
            '4.1' => [
                // Overlaps what the air already said: left alone.
                self::programme($now + 600, 1800, 'Online, over the top'),
                // Beyond where the air stops: taken.
                self::programme($now + 3600, 1800, 'Online, after it'),
            ],
            '69.3' => [self::programme($now, 1800, 'Online, the only guide there is')],
        ], $now);

        // Not "identified": a half-hour programme sitting inside an hour of broadcast is
        // not the same showing as it, so nothing is said about which episode that hour is.
        $this->assertSame(['channels' => 2, 'added' => 2, 'identified' => 0, 'skipped' => 1], $counts);

        $titles = fn (string $virtual): array => array_column($this->events($store, $virtual), 'title');

        $this->assertSame(['From the air', 'Online, after it'], $titles('4.1'), 'the air first, and only then the filler');
        $this->assertSame(['Online, the only guide there is'], $titles('69.3'));
    }

    public function testFetchingAgainReplacesItsOwnWorkAndNotTheAirs(): void
    {
        $store = new GuideStore($this->directory . '/guide.sqlite');
        $now   = 1_800_000_000;

        $this->seed($store, 'tuner', [['virtual' => '4.1', 'name' => 'WFOR', 'events' => [
            ['eventId' => 11, 'start' => $now, 'duration' => 3600, 'title' => 'From the air'],
        ]]]);

        $store->fillGapsFromXmltv('tuner', ['4.1' => [self::programme($now + 3600, 1800, 'First answer')]], $now);
        $store->fillGapsFromXmltv('tuner', ['4.1' => [self::programme($now + 3600, 1800, 'Second answer')]], $now);

        $this->assertSame(
            ['From the air', 'Second answer'],
            array_column($this->events($store, '4.1'), 'title'),
            'one filler, replaced, with the broadcast untouched beside it'
        );
    }

    public function testTheAirTakingOverRemovesTheStandIn(): void
    {
        $store = new GuideStore($this->directory . '/guide.sqlite');
        $now   = 1_800_000_000;

        $this->seed($store, 'tuner', [['virtual' => '69.3', 'name' => 'GREAT', 'events' => []]]);
        $store->fillGapsFromXmltv('tuner', ['69.3' => [self::programme($now, 3600, 'Stood in for it')]], $now);

        $this->assertSame(['Stood in for it'], array_column($this->events($store, '69.3'), 'title'));

        // The station starts sending a guide. What it says replaces what was guessed for it.
        $this->seed($store, 'tuner', [['virtual' => '69.3', 'name' => 'GREAT', 'events' => [
            ['eventId' => 7, 'start' => $now, 'duration' => 3600, 'title' => 'The station itself'],
        ]]]);

        $this->assertSame(['The station itself'], array_column($this->events($store, '69.3'), 'title'));
    }

    public function testTheSameShowingLendsItsEpisodeToTheBroadcast(): void
    {
        // The two guides do not agree to the second -- one says 03:34, the other 03:35 --
        // and the broadcast says nothing about which episode it is. So the listing stays
        // the broadcast's and only the identity is taken.
        $store = new GuideStore($this->directory . '/guide.sqlite');
        $now   = 1_800_000_000;

        $this->seed($store, 'tuner', [['virtual' => '6.1', 'name' => 'WTVJ', 'events' => [
            ['eventId' => 11, 'start' => $now + 60, 'duration' => 3600, 'title' => 'The Tonight Show'],
        ]]]);

        $counts = $store->fillGapsFromXmltv('tuner', ['6.1' => [[
            'start'           => $now, 'duration' => 3600, 'title' => 'The Tonight Show',
            'description'     => 'Guests', 'rating' => null, 'subTitle' => 'Hailee Steinfeld',
            'programmeId'     => 'EP01721107.2622', 'episode' => 'S14E07',
            'originalAirDate' => $now - 14 * 86400, 'repeat' => true,
        ]]], $now);

        $this->assertSame(1, $counts['identified']);
        $this->assertSame(0, $counts['added'], 'the broadcast keeps its own listing');

        $events = $this->events($store, '6.1');

        $this->assertCount(1, $events);
        $this->assertSame('The Tonight Show', $events[0]['title']);
        $this->assertSame('S14E07', $events[0]['episode']);
        $this->assertSame('EP01721107.2622', $events[0]['programmeId']);
        $this->assertSame('Hailee Steinfeld', $events[0]['subTitle']);
        $this->assertTrue($events[0]['repeat']);
        $this->assertSame('broadcast', $events[0]['source'], 'still the broadcast speaking');
    }

    public function testADifferentShowingLendsNothing(): void
    {
        // A film the online guide lists as two hours, against four half-hour programmes the
        // broadcast lists under it. All of each half hour lies inside the film, so a looser
        // rule would label all four of them with the film's episode.
        $store = new GuideStore($this->directory . '/guide.sqlite');
        $now   = 1_800_000_000;

        $this->seed($store, 'tuner', [['virtual' => '7.1', 'name' => 'WSVN', 'events' => array_map(
            static fn (int $i): array => [
                'eventId' => 20 + $i, 'start' => $now + $i * 1800, 'duration' => 1800, 'title' => "Part $i",
            ],
            [0, 1, 2, 3]
        )]]);

        $store->fillGapsFromXmltv('tuner', ['7.1' => [[
            'start'           => $now, 'duration' => 7200, 'title' => 'A Long Film',
            'description'     => null, 'rating' => null, 'subTitle' => null,
            'programmeId'     => 'MV00004362.0000', 'episode' => null,
            'originalAirDate' => null, 'repeat' => false,
        ]]], $now);

        foreach ($this->events($store, '7.1') as $event) {
            $this->assertNull($event['programmeId'], "{$event['title']} is not the film");
        }
    }

    public function testAnEpisodeIsKeptWithAListingTheAirNeverCarried(): void
    {
        $store = new GuideStore($this->directory . '/guide.sqlite');
        $now   = 1_800_000_000;

        $this->seed($store, 'tuner', [['virtual' => '69.3', 'name' => 'GREAT', 'events' => []]]);

        $store->fillGapsFromXmltv('tuner', ['69.3' => [[
            'start'           => $now, 'duration' => 3600, 'title' => 'The Rockford Files',
            'description'     => null, 'rating' => null, 'subTitle' => 'The Kirkoff Case',
            'programmeId'     => 'EP00018693.0002', 'episode' => 'S01E02',
            'originalAirDate' => 157_766_400, 'repeat' => true,
        ]]], $now);

        $event = $this->events($store, '69.3')[0];

        $this->assertSame('S01E02', $event['episode']);
        $this->assertSame('EP00018693.0002', $event['programmeId']);
        $this->assertSame('online', $event['source']);
    }

    /** @return array{start: int, duration: int, title: string, description: ?string, rating: ?string} */
    private static function programme(int $start, int $duration, string $title): array
    {
        return ['start' => $start, 'duration' => $duration, 'title' => $title, 'description' => null, 'rating' => null];
    }

    /**
     * @param list<array{virtual: string, name: string, events: list<array<string, mixed>>}> $channels
     */
    private function seed(GuideStore $store, string $device, array $channels): void
    {
        $physical = 10;

        foreach ($channels as $channel) {
            $store->saveChannelGuide($device, $physical++, 1234, [[
                'channel'       => $channel['virtual'],
                'name'          => $channel['name'],
                'programNumber' => 1,
                'sourceId'      => 1,
                'streams'       => [],
                'events'        => array_map(static fn (array $e): array => [
                    'eventId'         => $e['eventId'],
                    'start'           => gmdate('c', $e['start']),
                    'durationSeconds' => $e['duration'],
                    'title'           => $e['title'],
                    'rating'          => null,
                    'description'     => null,
                ], $channel['events']),
            ]]);
        }
    }

    /** @return list<array<string, mixed>> */
    private function events(GuideStore $store, string $virtual): array
    {
        foreach ($store->getGuide(0, 2_000_000_000, 'tuner') as $channel) {
            if ((string) $channel['virtual'] === $virtual) {
                return $channel['events'];
            }
        }

        return [];
    }
}
