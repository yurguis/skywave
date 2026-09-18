<?php

namespace Skywave\Tests\Web;

use PHPUnit\Framework\TestCase;
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
