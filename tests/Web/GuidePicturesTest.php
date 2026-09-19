<?php

namespace Skywave\Tests\Web;

use PHPUnit\Framework\TestCase;
use Skywave\Dvr\RecordingStore;
use Skywave\Guide\GuideStore;
use Skywave\Guide\ProgrammeArtwork;
use Skywave\Hdhomerun\Discovery;
use Skywave\Web\Api;
use Symfony\Component\HttpFoundation\Request;

/**
 * The guide says which pictures exist.
 *
 * Without this the page asks for every logo and every poster and takes a 404 for most of
 * them: six channels here have no logo and never will, and five hundred of the titles a
 * broadcast lists have no picture anywhere. It asked again on every refresh.
 */
class GuidePicturesTest extends TestCase
{
    private const DEVICE = '192.168.1.62';

    private string $directory;

    private GuideStore $guide;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/skywave-pictures-' . bin2hex(random_bytes(6));
        mkdir($this->directory . '/logos', 0777, true);
        mkdir($this->directory . '/artwork', 0777, true);

        putenv('GUIDE_DB=' . $this->directory . '/guide.sqlite');

        $this->guide = new GuideStore($this->directory . '/guide.sqlite');
        $this->guide->saveLineup(self::DEVICE, [
            ['physical' => 29, 'program' => 3, 'virtual' => '6.1', 'name' => 'WTVJ', 'tsid' => 627, 'encrypted' => false, 'hd' => true],
        ]);
    }

    protected function tearDown(): void
    {
        putenv('GUIDE_DB');
        exec('rm -rf ' . escapeshellarg($this->directory));
    }

    public function testAChannelWithNoLogoSaysSo(): void
    {
        $channel = $this->guideChannel();

        $this->assertArrayHasKey('logo', $channel);
        $this->assertFalse($channel['logo']);
    }

    public function testAChannelWithALogoSaysSo(): void
    {
        file_put_contents($this->directory . '/logos/6.1.png', 'pretend png');

        $this->assertTrue($this->guideChannel()['logo']);
    }

    public function testAProgrammeWithNoPictureSaysSo(): void
    {
        $this->addEvent('Paid Programming');

        $events = $this->guideChannel()['events'];

        $this->assertCount(1, $events);
        $this->assertArrayHasKey('art', $events[0]);
        $this->assertFalse($events[0]['art']);
    }

    public function testAProgrammeWithAPictureSaysSo(): void
    {
        $this->addEvent('Criminal Minds');
        $key = ProgrammeArtwork::key('Criminal Minds');
        file_put_contents($this->directory . "/artwork/$key.jpg", 'pretend jpeg');

        $this->assertTrue($this->guideChannel()['events'][0]['art']);
    }

    public function testEachProgrammeIsJudgedOnItsOwn(): void
    {
        $this->addEvent('Criminal Minds', time() + 600);
        $this->addEvent('Paid Programming', time() + 4000, 77);
        file_put_contents($this->directory . '/artwork/' . ProgrammeArtwork::key('Criminal Minds') . '.jpg', 'pretend jpeg');

        $art = [];

        foreach ($this->guideChannel()['events'] as $event) {
            $art[$event['title']] = $event['art'];
        }

        $this->assertSame(['Criminal Minds' => true, 'Paid Programming' => false], $art);
    }

    public function testARecordingWithAPictureSaysSo(): void
    {
        $recordings = new RecordingStore($this->directory . '/guide.sqlite');
        $this->addRecording($recordings, 'Criminal Minds');
        $this->addRecording($recordings, 'Paid Programming');
        file_put_contents($this->directory . '/artwork/' . ProgrammeArtwork::key('Criminal Minds') . '.jpg', 'pretend jpeg');

        $api      = new Api(new Discovery(), [], null, $this->guide, null, $recordings);
        $response = $api->handle(Request::create('/api/recordings', 'GET'));
        $body     = json_decode((string) $response->getContent(), true);

        $art = [];

        foreach ($body['recordings'] as $recording) {
            $art[$recording['title']] = $recording['art'];
        }

        // Order is the list's business, not this test's.
        $this->assertTrue($art['Criminal Minds']);
        $this->assertFalse($art['Paid Programming']);
    }

    private function addRecording(RecordingStore $recordings, string $title): void
    {
        $recordings->addRecording([
            'scheduleId'  => null,
            'device'      => self::DEVICE,
            'physical'    => 29,
            'program'     => 3,
            'virtual'     => '6.1',
            'channelName' => 'WTVJ',
            'title'       => $title,
            'description' => null,
            'path'        => $title . '.ts',
            'format'      => 'ts',
            'tuner'       => 0,
            'pid'         => 1234,
            'startedAt'   => time() - 3600,
            'stopsAt'     => time() - 60,
            'reservation' => null,
        ]);
    }

    public function testAnAtsc3ChannelBorrowsTheLogoOfTheChannelItSimulcasts(): void
    {
        // A 3.0 service is numbered a hundred above the channel it carries, and the
        // station's logo is filed under that lower number.
        file_put_contents($this->directory . '/logos/2.1.png', 'pretend png');
        $this->saveAtsc3('102.1', 'WPBT-HD');

        $station = $this->guideChannelFor('102.1');

        $this->assertTrue($station['logo']);
        $this->assertSame('2.1', $station['logoFor']);
    }

    public function testAnAtsc3ChannelPrefersItsOwnLogo(): void
    {
        file_put_contents($this->directory . '/logos/104.1.png', 'pretend png');
        file_put_contents($this->directory . '/logos/4.1.png', 'pretend png');
        $this->saveAtsc3('104.1', 'WFOR-TV');

        $station = $this->guideChannelFor('104.1');

        $this->assertTrue($station['logo']);
        // Nothing to redirect to: the page should ask for the number it already has.
        $this->assertArrayNotHasKey('logoFor', $station);
    }

    public function testAnAtsc3ChannelWithNoCounterpartLogoHasNone(): void
    {
        $this->saveAtsc3('102.5', 'PBSWRLD');

        $station = $this->guideChannelFor('102.5');

        $this->assertFalse($station['logo']);
        $this->assertArrayNotHasKey('logoFor', $station);
    }

    public function testAnOrdinaryChannelNeverBorrowsAnotherLogo(): void
    {
        // The borrowing is only ever right for a 3.0 simulcast. A broadcast channel that
        // happens to be numbered above a hundred is a different station entirely.
        file_put_contents($this->directory . '/logos/2.1.png', 'pretend png');
        $this->guide->saveLineup(self::DEVICE, [
            ['physical' => 29, 'program' => 3, 'virtual' => '6.1', 'name' => 'WTVJ', 'tsid' => 627, 'encrypted' => false, 'hd' => true],
            ['physical' => 31, 'program' => 1, 'virtual' => '102.1', 'name' => 'Other', 'tsid' => 700, 'encrypted' => false, 'hd' => false],
        ]);

        $station = $this->guideChannelFor('102.1');

        $this->assertFalse($station['logo']);
        $this->assertArrayNotHasKey('logoFor', $station);
    }

    private function saveAtsc3(string $virtual, string $name): void
    {
        $this->guide->saveAtsc3Lineup(self::DEVICE, [[
            'virtual'    => $virtual,
            'name'       => $name,
            'videoCodec' => 'HEVC',
            'audioCodec' => 'AC4',
            'drm'        => false,
            'broadband'  => true,
            'hd'         => false,
        ]], GuideStore::ATSC3_SOURCE_SLT);
    }

    /**
     * @return array<string, mixed>
     */
    private function guideChannelFor(string $virtual): array
    {
        foreach ($this->guideChannels() as $channel) {
            if ($channel['virtual'] === $virtual) {
                return $channel;
            }
        }

        $this->fail("No channel $virtual in the guide");
    }

    /**
     * @return array<string, mixed>
     */
    private function guideChannel(): array
    {
        $api = new Api(new Discovery(), [], null, $this->guide);

        $response = $api->handle(Request::create(
            '/api/guide?device=' . self::DEVICE . '&hours=6&from=' . (time() - 60),
            'GET'
        ));

        $body = json_decode((string) $response->getContent(), true);

        return $body['channels'][0] ?? [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function guideChannels(): array
    {
        $api = new Api(new Discovery(), [], null, $this->guide);

        $response = $api->handle(Request::create(
            '/api/guide?device=' . self::DEVICE . '&hours=6&from=' . (time() - 60),
            'GET'
        ));

        return json_decode((string) $response->getContent(), true)['channels'] ?? [];
    }

    private function addEvent(string $title, ?int $start = null, int $eventId = 42): void
    {
        $db = new \PDO('sqlite:' . $this->directory . '/guide.sqlite', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);

        $find = $db->prepare('SELECT id FROM channels WHERE device = ? AND physical = ? AND program = ?');
        $find->execute([self::DEVICE, 29, 3]);

        $db->prepare(
            'INSERT OR REPLACE INTO events (channel_id, event_id, start, duration, title, rating, description, updated_at)
             VALUES (?, ?, ?, ?, ?, NULL, NULL, ?)'
        )->execute([(int) $find->fetchColumn(), $eventId, $start ?? time() + 600, 1800, $title, time()]);
    }
}
