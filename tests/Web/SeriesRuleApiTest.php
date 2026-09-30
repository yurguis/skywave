<?php

namespace Skywave\Tests\Web;

use PHPUnit\Framework\TestCase;
use Skywave\Dvr\RecordingStore;
use Skywave\Dvr\SeriesRules;
use Skywave\Guide\GuideStore;
use Skywave\Hdhomerun\Discovery;
use Skywave\Web\Api;
use Symfony\Component\HttpFoundation\Request;

/**
 * The rules endpoints as the page meets them: what it sends, what comes back, and what a
 * malformed request is told. No tuner is involved, so none of this touches the network.
 */
class SeriesRuleApiTest extends TestCase
{
    private string $directory;

    private RecordingStore $recordings;

    private Api $api;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/skywave-ruleapi-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
        $path             = $this->directory . '/guide.sqlite';
        $guide            = new GuideStore($path);
        $this->recordings = new RecordingStore($path);

        $this->api = new Api(
            new Discovery(),
            [],
            null,
            $guide,
            null,
            $this->recordings,
            null,
            null,
            null,
            null,
            new SeriesRules($guide, $this->recordings)
        );
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->directory);
    }

    public function testARuleCanBeMadeListedAndCancelled(): void
    {
        $created = $this->send('POST', '/api/recordings/rules', $this->body());

        $this->assertSame(200, $created['status']);
        $this->assertSame('Jeopardy!', $created['body']['rule']['title']);
        $this->assertSame('America/New_York', $created['body']['rule']['timezone']);

        $listed = $this->send('GET', '/api/recordings/rules');

        $this->assertCount(1, $listed['body']['rules']);

        $cancelled = $this->send('DELETE', '/api/recordings/rules/' . $created['body']['id']);

        $this->assertTrue($cancelled['body']['cancelled']);
        $this->assertSame([], $this->recordings->getRules());
    }

    public function testTheSameSeriesTwiceStaysOneRule(): void
    {
        $first  = $this->send('POST', '/api/recordings/rules', $this->body());
        $second = $this->send('POST', '/api/recordings/rules', $this->body());

        $this->assertSame($first['body']['id'], $second['body']['id']);
        $this->assertCount(1, $this->recordings->getRules());
    }

    public function testARuleWithoutATitleIsRefused(): void
    {
        $response = $this->send('POST', '/api/recordings/rules', $this->body(['title' => '   ']));

        $this->assertSame(400, $response['status']);
        $this->assertStringContainsString('title', $response['body']['error']);
    }

    public function testAnImpossibleHourIsRefused(): void
    {
        $response = $this->send('POST', '/api/recordings/rules', $this->body(['earliest' => 2000, 'latest' => 100]));

        $this->assertSame(400, $response['status']);
        $this->assertStringContainsString('minute of the day', $response['body']['error']);
    }

    public function testAnImpossibleWeekdayIsRefused(): void
    {
        $response = $this->send('POST', '/api/recordings/rules', $this->body(['days' => [1, 9]]));

        $this->assertSame(400, $response['status']);
        $this->assertStringContainsString('weekdays', $response['body']['error']);
    }

    public function testAPublicAddressIsRefused(): void
    {
        $response = $this->send('POST', '/api/recordings/rules', $this->body(['device' => '8.8.8.8']));

        $this->assertSame(400, $response['status']);
    }

    public function testTheHoursAndDaysComeBackAsTheyWentIn(): void
    {
        $created = $this->send('POST', '/api/recordings/rules', $this->body([
            'earliest' => 18 * 60,
            'latest'   => 20 * 60,
            'days'     => [1, 2, 3, 4, 5],
        ]));

        $this->assertSame(18 * 60, $created['body']['rule']['earliest']);
        $this->assertSame(20 * 60, $created['body']['rule']['latest']);
        $this->assertSame('1,2,3,4,5', $created['body']['rule']['days']);
    }

    public function testWhatASeriesKeepsCanBeChangedAfterItIsScheduled(): void
    {
        $id = $this->send('POST', '/api/recordings/rules', $this->body())['body']['id'];

        $changed = $this->send('PATCH', "/api/recordings/rules/$id", ['format' => 'both']);

        $this->assertSame(200, $changed['status']);
        $this->assertSame('both', $changed['body']['format']);
        $this->assertSame('both', $this->recordings->getRules()[0]['format']);
    }

    public function testAFormatNothingRecordsInIsRefused(): void
    {
        $id = $this->send('POST', '/api/recordings/rules', $this->body())['body']['id'];

        $response = $this->send('PATCH', "/api/recordings/rules/$id", ['format' => 'mkv']);

        $this->assertSame(400, $response['status']);
        $this->assertSame('ts', $this->recordings->getRules()[0]['format'], 'the rule is left alone');
    }

    public function testChangingARuleThatIsNotThereIsNotFound(): void
    {
        $response = $this->send('PATCH', '/api/recordings/rules/999', ['format' => 'both']);

        $this->assertSame(404, $response['status']);
    }

    public function testShowingsAlreadyScheduledKeepWhatTheyWereGiven(): void
    {
        // Deliberate, and pinned here so it is not quietly changed. A schedule is handed a
        // copy of the format when it is made and nothing ties one back to the rule that
        // made it, so cascading would mean finding them by what the rule looks for -- which
        // would also catch a showing somebody scheduled by hand.
        $id = $this->send('POST', '/api/recordings/rules', $this->body())['body']['id'];

        $this->recordings->addSchedule([
            'device'      => '192.168.1.62',
            'physical'    => 29,
            'program'     => 3,
            'virtual'     => '6.1',
            'channelName' => 'WTVJ',
            'eventId'     => null,
            'start'       => time() + 3600,
            'duration'    => 1800,
            'title'       => 'Jeopardy!',
            'description' => null,
            'padStart'    => 60,
            'padEnd'      => 180,
            'format'      => 'ts',
        ]);

        $this->send('PATCH', "/api/recordings/rules/$id", ['format' => 'mp4']);

        $this->assertSame('mp4', $this->recordings->getRules()[0]['format']);
        $this->assertSame('ts', $this->recordings->getSchedules()[0]['format']);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function body(array $overrides = []): array
    {
        return $overrides + [
            'device'      => '192.168.1.62',
            'physical'    => 29,
            'program'     => 3,
            'virtual'     => '6.1',
            'channelName' => 'WTVJ',
            'title'       => 'Jeopardy!',
            'timezone'    => 'America/New_York',
            'format'      => 'ts',
        ];
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array{status: int, body: array<string, mixed>}
     */
    private function send(string $method, string $path, ?array $body = null): array
    {
        $request  = Request::create($path, $method, [], [], [], [], $body === null ? null : json_encode($body));
        $response = $this->api->handle($request);

        return [
            'status' => $response->getStatusCode(),
            'body'   => json_decode((string) $response->getContent(), true),
        ];
    }
}
