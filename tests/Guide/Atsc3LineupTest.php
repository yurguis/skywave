<?php

namespace Skywave\Tests\Guide;

use PHPUnit\Framework\TestCase;
use Skywave\Guide\GuideStore;

/**
 * ATSC 3.0 stations are listed, and never tuned.
 *
 * A scan cannot find them: 3.0 carries ROUTE/DASH over ALP rather than an MPEG transport
 * stream, so they come from the device's own lineup instead. They are shown so that what is
 * on the air is visible, but they must stay out of the lineup that drives scanning, tuning
 * and recording — a physical channel that carries no transport stream is not something to
 * point a tuner at.
 */
class Atsc3LineupTest extends TestCase
{
    private const DEVICE = '192.168.1.62';

    private string $directory;

    private GuideStore $store;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/skywave-atsc3-' . bin2hex(random_bytes(6));
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

    public function testTheyStayOutOfTheLineupThatDrivesTuning(): void
    {
        $this->save();

        $lineup = $this->store->getLineup(self::DEVICE);

        $this->assertCount(1, $lineup);
        $this->assertSame('6.1', $lineup[0]['virtual']);
    }

    public function testTheyAreListedInTheGuide(): void
    {
        $this->save();

        $virtuals = array_column($this->store->getGuide(time() - 60, time() + 3600, self::DEVICE), 'virtual');

        $this->assertSame(['6.1', '104.1'], $virtuals);
    }

    public function testAListedStationCarriesNoEventsAndSaysWhatItIs(): void
    {
        $this->save();

        $station = $this->guideChannel('104.1');

        $this->assertSame([], $station['events']);
        $this->assertTrue($station['atsc3']);
        $this->assertTrue($station['drm']);
        $this->assertSame('HEVC', $station['videoCodec']);
        $this->assertSame('AC4', $station['audioCodec']);
    }

    public function testAnEncryptedStationCannotBeWatched(): void
    {
        // The page disables its watch button on this flag, which is the whole point.
        $this->save();

        $this->assertTrue($this->guideChannel('104.1')['encrypted']);
    }

    public function testAStationWithoutDrmIsNotMarkedEncrypted(): void
    {
        $this->save([$this->station('104.1', 'WFOR-TV', drm: false)]);

        $this->assertFalse($this->guideChannel('104.1')['encrypted']);
        $this->assertFalse($this->guideChannel('104.1')['drm']);
    }

    public function testSavingAgainReplacesRatherThanDuplicates(): void
    {
        $this->save();
        $this->save([$this->station('106.1', 'WTVJ-DT')]);

        $virtuals = array_column($this->store->getAtsc3Lineup(self::DEVICE), 'virtual');

        $this->assertSame(['106.1'], $virtuals);
    }

    public function testADeviceReportingNoneClearsWhatItHad(): void
    {
        $this->save();
        $this->save([]);

        $this->assertSame([], $this->store->getAtsc3Lineup(self::DEVICE));
    }

    public function testTheirIdsCannotCollideWithRealChannels(): void
    {
        $this->save();

        $ids = array_column($this->store->getGuide(time() - 60, time() + 3600, self::DEVICE), 'id');

        $this->assertContainsOnly('string', array_map('strval', $ids));
        $this->assertNotContains($this->store->getLineup(self::DEVICE)[0]['id'], array_column($this->store->getAtsc3Lineup(self::DEVICE), 'id'));
    }

    public function testASecondSourceDoesNotEraseTheFirst(): void
    {
        // The device's lineup omits the unencrypted stations entirely, so they are read
        // from the broadcast instead. The guide re-saves the lineup every few hours, and
        // that must not take the others with it.
        $this->save();
        $this->store->saveAtsc3Lineup(
            self::DEVICE,
            [$this->station('2.1', 'WPBT-HD', drm: false)],
            GuideStore::ATSC3_SOURCE_SLT
        );

        $this->save();

        $virtuals = array_column($this->store->getAtsc3Lineup(self::DEVICE), 'virtual');
        // Sorted as text: PHP orders numeric-looking strings by value otherwise, which
        // would put 2.1 before 104.1 and make the expectation read oddly.
        sort($virtuals, SORT_STRING);

        $this->assertSame(['104.1', '2.1'], $virtuals);
    }

    public function testASourceStillReplacesItsOwnRows(): void
    {
        $this->store->saveAtsc3Lineup(self::DEVICE, [$this->station('2.1', 'WPBT-HD')], GuideStore::ATSC3_SOURCE_SLT);
        $this->store->saveAtsc3Lineup(self::DEVICE, [$this->station('2.5', 'PBSWRLD')], GuideStore::ATSC3_SOURCE_SLT);

        $this->assertSame(['2.5'], array_column($this->store->getAtsc3Lineup(self::DEVICE), 'virtual'));
    }

    public function testEachStationSaysWhereItCameFrom(): void
    {
        $this->save();
        $this->store->saveAtsc3Lineup(self::DEVICE, [$this->station('2.1', 'WPBT-HD')], GuideStore::ATSC3_SOURCE_SLT);

        $source = [];

        foreach ($this->store->getAtsc3Lineup(self::DEVICE) as $station) {
            $source[$station['virtual']] = $station['source'];
        }

        $this->assertSame(['104.1' => 'lineup', '2.1' => 'slt'], $source);
    }

    public function testAnUnencryptedStationIsNotMarkedDrm(): void
    {
        $this->store->saveAtsc3Lineup(
            self::DEVICE,
            [$this->station('2.1', 'WPBT-HD', drm: false)],
            GuideStore::ATSC3_SOURCE_SLT
        );

        $station = $this->guideChannel('2.1');

        $this->assertFalse($station['drm']);
        $this->assertTrue($station['atsc3']);
        $this->assertSame([], $station['events']);
    }

    /**
     * @param list<array<string, mixed>>|null $stations
     */
    private function save(?array $stations = null): void
    {
        $this->store->saveAtsc3Lineup(self::DEVICE, $stations ?? [$this->station('104.1', 'WFOR-TV')]);
    }

    /**
     * @return array<string, mixed>
     */
    private function station(string $virtual, string $name, bool $drm = true): array
    {
        return [
            'virtual'    => $virtual,
            'name'       => $name,
            'videoCodec' => 'HEVC',
            'audioCodec' => 'AC4',
            'drm'        => $drm,
            'hd'         => true,
        ];
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
