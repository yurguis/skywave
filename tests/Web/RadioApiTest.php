<?php

namespace Skywave\Tests\Web;

use PHPUnit\Framework\TestCase;
use Skywave\Hdhomerun\Discovery;
use Skywave\Radio\Receiver;
use Skywave\Radio\ScanJobs;
use Skywave\Radio\StationStore;
use Skywave\Web\Api;
use Skywave\Web\LiveStreams;
use Symfony\Component\HttpFoundation\Request;

/**
 * What the page is told about the radio before any station is played.
 *
 * Nothing here starts nrsc5: these are the answers given when there is no radio, when it
 * cannot work, and when it is asked for something that is not a station.
 */
class RadioApiTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/skywave-radioapi-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        // A session lock, a scan lock in its own folder, and a database with its journals.
        exec('rm -rf ' . escapeshellarg($this->directory));
    }

    public function testAServerWithoutARadioSimplySaysSo(): void
    {
        // nrsc5 being installed is not a radio: it says nothing about whether there is a
        // dongle, so only a dongle's whereabouts turns it on.
        $this->assertSame(['enabled' => false], $this->get($this->api(new Receiver(PHP_BINARY)), '/api/radio')[1]);
        $this->assertSame(['enabled' => false], $this->get($this->api(null), '/api/radio')[1]);
    }

    public function testListeningWithoutARadioIsNotFound(): void
    {
        [$status, $body] = $this->post($this->api(new Receiver(PHP_BINARY)), ['frequency' => 90.5, 'viewer' => 'viewer-0001']);

        $this->assertSame(404, $status);
        $this->assertStringContainsString('RADIO_RTL_TCP', $body['error']);
    }

    public function testARadioWithoutNrsc5SaysWhatIsMissing(): void
    {
        $api = $this->api(new Receiver('/nowhere/nrsc5', null, 0));

        [, $radio] = $this->get($api, '/api/radio');

        $this->assertTrue($radio['enabled']);
        $this->assertSame('usb', $radio['source']);
        $this->assertStringContainsString('nrsc5 is not installed', $radio['error']);

        [$status, $body] = $this->post($api, ['frequency' => 90.5, 'viewer' => 'viewer-0001']);

        $this->assertSame(503, $status);
        $this->assertStringContainsString('nrsc5 is not installed', $body['error']);
    }

    public function testAnIdleRadioDescribesItsDongle(): void
    {
        [$status, $radio] = $this->get($this->api(new Receiver(PHP_BINARY, null, 0)), '/api/radio');

        $this->assertSame(200, $status);
        $this->assertNull($radio['error']);
        $this->assertNull($radio['session']);
        $this->assertSame('USB dongle 0', $radio['label']);
        $this->assertSame(['from' => 87.5, 'to' => 108], $radio['band']);
    }

    public function testSomethingThatIsNotAStationIsRefusedBeforeAnythingStarts(): void
    {
        $api = $this->api(new Receiver(PHP_BINARY, null, 0));

        $this->assertSame(400, $this->post($api, ['frequency' => 162.55, 'viewer' => 'viewer-0001'])[0]);
        $this->assertSame(400, $this->post($api, ['frequency' => '90.5', 'viewer' => 'viewer-0001'])[0]);
        $this->assertSame(400, $this->post($api, ['frequency' => 90.5, 'program' => 8, 'viewer' => 'viewer-0001'])[0]);
        $this->assertSame(400, $this->post($api, ['frequency' => 90.5])[0]);

        // And nothing was started on the way to saying no.
        $this->assertSame([], glob($this->directory . '/*/session.json') ?: []);
    }

    public function testStationsAreListedOnceThereIsSomewhereToKeepThem(): void
    {
        // No database: null rather than empty, so the page knows to keep its own.
        $this->assertNull($this->get($this->api(new Receiver(PHP_BINARY, null, 0)), '/api/radio')[1]['stations']);

        $stations = new StationStore($this->directory . '/guide.sqlite');
        $stations->save(90.5, 'KUT');

        $api = new Api(new Discovery(), [], new LiveStreams($this->directory), null, null, null, null, null, null, null, null, new Receiver(PHP_BINARY, null, 0), $stations, new ScanJobs($this->directory . '/scan', '/nowhere/radio-scan.php'));

        [, $radio] = $this->get($api, '/api/radio');

        $this->assertSame('KUT', $radio['stations'][0]['name']);
        $this->assertNull($radio['scan']);

        $response = $api->handle(Request::create('/api/radio/stations/90.5', 'DELETE'));
        $body     = json_decode((string) $response->getContent(), true);

        $this->assertTrue($body['removed']);
        $this->assertSame([], $body['stations']);

    }

    public function testAScanThatMakesNoSenseIsRefusedBeforeAnythingStarts(): void
    {
        $jobs = new ScanJobs($this->directory . '/scan', '/nowhere/radio-scan.php');
        $api  = new Api(new Discovery(), [], new LiveStreams($this->directory), null, null, null, null, null, null, null, null, new Receiver(PHP_BINARY, null, 0), null, $jobs);

        $scan = static fn (array $body) => $api->handle(Request::create('/api/radio/scan', 'POST', [], [], [], [], (string) json_encode($body)))->getStatusCode();

        // Backwards, off the dial, and between two stations with none in the gap.
        $this->assertSame(400, $scan(['from' => 100.1, 'to' => 90.1]));
        $this->assertSame(400, $scan(['from' => 50, 'to' => 90.1]));
        $this->assertSame(400, $scan(['from' => 90.2, 'to' => 90.2]));
        $this->assertFalse($jobs->isRunning());

        // And a server with no radio has no scan to speak of.
        $this->assertSame(404, $this->api(null)->handle(Request::create('/api/radio/scan', 'GET'))->getStatusCode());
    }

    public function testAnAmStationCanBeForgottenAsWellAsAnFmOne(): void
    {
        // The route used to take two or three digits and one decimal, which is an FM
        // frequency and nothing else: 1.06 MHz did not match it at all, so an AM station
        // could be saved and never removed.
        $stations = new StationStore($this->directory . '/guide.sqlite');
        $stations->save(1.06, null, [], null, 'am');
        $stations->save(93.1, 'WFEZ', [], null, 'fm');

        $api = new Api(new Discovery(), [], new LiveStreams($this->directory), null, null, null, null, null, null, null, null, new Receiver(PHP_BINARY, null, 0), $stations, new ScanJobs($this->directory . '/scan', '/nowhere/radio-scan.php'));

        $response = $api->handle(Request::create('/api/radio/stations/1.06', 'DELETE'));
        $body     = json_decode((string) $response->getContent(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($body['removed']);
        $this->assertSame([93.1], array_column($body['stations'], 'frequency'), 'and only that one');
    }

    public function testTheRainSheetIsOfferedBesideTheTrafficTiles(): void
    {
        // What the listener's own process has written down, and what nrsc5 has put on disk
        // beside it. Both pictures are offered as urls the page can fetch; a picture named
        // but not yet written is withheld, because the page would only get a broken image.
        $streams = new LiveStreams($this->directory);
        $id      = '0123456789abcdef';

        mkdir("$this->directory/$id");
        touch("$this->directory/$id/100_trafficMap_1_1_znz1.png");
        touch("$this->directory/$id/100_WeatherImage_0_0_znz1.png");

        file_put_contents("$this->directory/$id/radio.json", (string) json_encode([
            'traffic' => [
                ['row' => 1, 'column' => 1, 'file' => '100_trafficMap_1_1_znz1.png', 'at' => 100],
                ['row' => 0, 'column' => 0, 'file' => '100_trafficMap_0_0_znz1.png', 'at' => 100],
            ],
            'weather' => ['file' => '100_WeatherImage_0_0_znz1.png', 'at' => 100, 'north' => 26.3577],
        ]));

        file_put_contents("$this->directory/$id/session.json", (string) json_encode([
            'id'        => $id,
            'host'      => 'radio',
            'tuner'     => null,
            'channel'   => '104.3 FM HD1',
            'program'   => 0,
            'pid'       => 0,
            'startedAt' => time(),
            'endedAt'   => time(),
            'viewers'   => [],
            'audio'     => [],
            'radio'     => ['frequency' => 104.3, 'program' => 0],
        ]));

        $station = $streams->all()[0]['radio'];

        // The tile that is on disk, and not the one that is only spoken of.
        $this->assertCount(1, $station['traffic']);
        $this->assertSame("/radio/$id/100_trafficMap_1_1_znz1.png", $station['traffic'][0]['url']);

        $this->assertSame("/radio/$id/100_WeatherImage_0_0_znz1.png", $station['weather']['url']);
        $this->assertSame(26.3577, $station['weather']['north']);
        $this->assertArrayNotHasKey('file', $station['weather'], 'the page is given a url, not a path');
    }

    public function testRainThatHasNotLandedYetIsNotOffered(): void
    {
        $streams = new LiveStreams($this->directory);
        $id      = 'fedcba9876543210';

        mkdir("$this->directory/$id");

        // Named by nrsc5 but not yet written out, which is most of the time it is arriving.
        file_put_contents("$this->directory/$id/radio.json", (string) json_encode([
            'weather' => ['file' => '100_WeatherImage_0_0_znz1.png', 'at' => 100],
        ]));

        file_put_contents("$this->directory/$id/session.json", (string) json_encode([
            'id'        => $id,
            'host'      => 'radio',
            'tuner'     => null,
            'channel'   => '104.3 FM HD1',
            'program'   => 0,
            'pid'       => 0,
            'startedAt' => time(),
            'endedAt'   => time(),
            'viewers'   => [],
            'audio'     => [],
            'radio'     => ['frequency' => 104.3, 'program' => 0],
        ]));

        $this->assertNull($streams->all()[0]['radio']['weather']);
    }

    public function testOnlyAPictureTheStationSentCanBeServed(): void
    {
        $streams = new LiveStreams($this->directory);
        $id      = '0123456789abcdef';

        mkdir("$this->directory/$id");
        touch("$this->directory/$id/4242_cover.jpg");
        touch("$this->directory/$id/session.json");

        $this->assertSame("$this->directory/$id/4242_cover.jpg", $streams->resolvePicture($id, '4242_cover.jpg'));
        $this->assertNull($streams->resolvePicture($id, 'session.json'));
        $this->assertNull($streams->resolvePicture($id, '../' . $id . '/4242_cover.jpg'));
        $this->assertNull($streams->resolvePicture($id, '1_missing.png'));
        $this->assertNull($streams->resolvePicture('not-an-id', '4242_cover.jpg'));

        unlink("$this->directory/$id/4242_cover.jpg");
        unlink("$this->directory/$id/session.json");
        rmdir("$this->directory/$id");
    }

    private function api(?Receiver $radio): Api
    {
        return new Api(new Discovery(), [], new LiveStreams($this->directory), null, null, null, null, null, null, null, null, $radio);
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function get(Api $api, string $path): array
    {
        $response = $api->handle(Request::create($path, 'GET'));

        return [$response->getStatusCode(), json_decode((string) $response->getContent(), true)];
    }

    /**
     * @param array<string, mixed> $body
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function post(Api $api, array $body): array
    {
        $response = $api->handle(Request::create('/api/radio/stream', 'POST', [], [], [], [], (string) json_encode($body)));

        return [$response->getStatusCode(), json_decode((string) $response->getContent(), true)];
    }
}
