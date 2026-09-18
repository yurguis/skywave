<?php

namespace Skywave\Tests\Web;

use PHPUnit\Framework\TestCase;
use Skywave\Dvr\RecordingStore;
use Skywave\Hdhomerun\Discovery;
use Skywave\Web\Api;
use Symfony\Component\HttpFoundation\Request;

/**
 * Asking for a recording to be converted, and being told why not.
 */
class ConvertApiTest extends TestCase
{
    private string $directory;

    private RecordingStore $recordings;

    private Api $api;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/skywave-convertapi-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
        $this->recordings = new RecordingStore($this->directory . '/guide.sqlite');

        $this->api = new Api(new Discovery(), [], null, null, null, $this->recordings);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->directory);
    }

    public function testAskingQueuesItAtTheBroadcastPictureSize(): void
    {
        $id = $this->addRecording();

        $response = $this->send('POST', "/api/recordings/$id/convert", []);

        $this->assertSame(200, $response['status']);
        $this->assertTrue($response['body']['queued']);
        $this->assertTrue($this->recordings->getRecording($id)['convertRequested']);
        $this->assertNull($this->recordings->getRecording($id)['convertHeight']);
        $this->assertCount(1, $this->recordings->getRecordingsToConvert());
    }

    public function testAPictureSizeCanBeChosen(): void
    {
        $id = $this->addRecording();

        $this->send('POST', "/api/recordings/$id/convert", ['height' => 720]);

        $this->assertSame(720, $this->recordings->getRecording($id)['convertHeight']);
    }

    public function testAnImpossiblePictureSizeIsRefused(): void
    {
        $id = $this->addRecording();

        $response = $this->send('POST', "/api/recordings/$id/convert", ['height' => 5]);

        $this->assertSame(400, $response['status']);
        $this->assertFalse($this->recordings->getRecording($id)['convertRequested']);
    }

    public function testOneThatAlreadyPlaysInABrowserIsRefused(): void
    {
        $id = $this->addRecording(['format' => 'mp4']);

        $this->assertSame(409, $this->send('POST', "/api/recordings/$id/convert", [])['status']);
    }

    public function testOneStillRecordingIsRefused(): void
    {
        $id = $this->addRecording([], RecordingStore::STATUS_RECORDING);

        $this->assertSame(409, $this->send('POST', "/api/recordings/$id/convert", [])['status']);
    }

    public function testAskingAgainAfterAFailureClearsTheError(): void
    {
        // The error is what excludes it from conversion, so a retry has to remove it.
        $id = $this->addRecording();
        $this->recordings->updateRecording($id, ['convertRequested' => 1, 'convertError' => 'ffmpeg gave up']);

        $this->send('POST', "/api/recordings/$id/convert", []);

        $this->assertNull($this->recordings->getRecording($id)['convertError']);
        $this->assertCount(1, $this->recordings->getRecordingsToConvert());
    }

    public function testAMissingRecordingIsNotFound(): void
    {
        $this->assertSame(404, $this->send('POST', '/api/recordings/999/convert', [])['status']);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function addRecording(array $overrides = [], string $status = RecordingStore::STATUS_DONE): int
    {
        $id = $this->recordings->addRecording($overrides + [
            'scheduleId'  => null,
            'device'      => '192.168.1.62',
            'physical'    => 29,
            'program'     => 3,
            'virtual'     => '6.1',
            'channelName' => 'WTVJ',
            'title'       => 'The Late Show',
            'description' => null,
            'path'        => 'The Late Show.ts',
            'format'      => 'ts',
            'tuner'       => 0,
            'pid'         => 1234,
            'startedAt'   => time() - 3600,
            'stopsAt'     => time() - 60,
            'reservation' => null,
        ]);

        $this->recordings->updateRecording($id, ['status' => $status, 'bytes' => 1_000_000, 'endedAt' => time() - 60]);

        return $id;
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array{status: int, body: array<string, mixed>}
     */
    private function send(string $method, string $path, ?array $body = null): array
    {
        $response = $this->api->handle(
            Request::create($path, $method, [], [], [], [], $body === null ? null : json_encode($body))
        );

        return ['status' => $response->getStatusCode(), 'body' => json_decode((string) $response->getContent(), true)];
    }
}
