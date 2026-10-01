<?php

namespace Skywave\Tests\Web;

use PHPUnit\Framework\TestCase;
use Skywave\Hdhomerun\Discovery;
use Skywave\Radio\Receiver;
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
        foreach (glob($this->directory . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        rmdir($this->directory);
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
