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

    public function testARuleKeepsTheBroadcastAndACopy(): void
    {
        // Nothing to choose and nothing to change later: every showing a rule makes keeps
        // the broadcast as it was sent, and a copy a browser can play.
        $created = $this->send('POST', '/api/recordings/rules', $this->body());

        $this->assertSame(RecordingStore::KEPT, $created['body']['rule']['format']);
    }

    public function testTheOnlyThingToChangeOnARuleIsWhetherItTakesRepeats(): void
    {
        $id = $this->send('POST', '/api/recordings/rules', $this->body())['body']['id'];

        // Nothing else is changeable: a rule is cheap to make again, and the format or the
        // padding of one already evaluated would not apply to what it has scheduled.
        $this->assertSame(400, $this->send('PATCH', "/api/recordings/rules/$id", ['format' => 'mp4'])['status']);
    }

    public function testARuleCanBeToldToTakeFirstShowingsOnly(): void
    {
        $created = $this->send('POST', '/api/recordings/rules', $this->body());
        $id      = $created['body']['id'];

        $this->assertFalse($created['body']['rule']['newOnly'], 'everything, until asked otherwise');

        $changed = $this->send('PATCH', "/api/recordings/rules/$id", ['newOnly' => true]);

        $this->assertSame(200, $changed['status']);
        $this->assertTrue($changed['body']['rules'][0]['newOnly']);

        $back = $this->send('PATCH', "/api/recordings/rules/$id", ['newOnly' => false]);

        $this->assertFalse($back['body']['rules'][0]['newOnly']);
    }

    public function testARuleCanBeMadeAskingForFirstShowingsOnly(): void
    {
        $created = $this->send('POST', '/api/recordings/rules', $this->body(['newOnly' => true]));

        $this->assertTrue($created['body']['rule']['newOnly']);
    }

    public function testASeriesCanBeToldToKeepRecordingAfterTheDueEnd(): void
    {
        // Sport runs over and the guide never says so, so this is the only way to catch it.
        $created = $this->send('POST', '/api/recordings/rules', $this->body(['padEnd' => 30 * 60]));

        $this->assertSame(30 * 60, $created['body']['rule']['padEnd']);
    }

    public function testAnHourLateIsAllowedWhereItWasNot(): void
    {
        // Extra innings. Half an hour used to be the most that could be asked for.
        $created = $this->send('POST', '/api/recordings/rules', $this->body(['padEnd' => 60 * 60]));

        $this->assertSame(60 * 60, $created['body']['rule']['padEnd']);
    }

    public function testAnAbsurdOverrunIsBroughtBackIntoRange(): void
    {
        $created = $this->send('POST', '/api/recordings/rules', $this->body(['padEnd' => 99999]));

        $this->assertSame(60 * 60, $created['body']['rule']['padEnd'], 'a typo cannot book the afternoon');
    }

    public function testStartingEarlyIsStillCappedAtHalfAnHour(): void
    {
        // Only the end was raised: starting an hour early holds a tuner for an hour to no
        // purpose, and nothing overruns backwards.
        $created = $this->send('POST', '/api/recordings/rules', $this->body(['padStart' => 60 * 60]));

        $this->assertSame(30 * 60, $created['body']['rule']['padStart']);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public function testAnEpisodeCanBeForgottenSoItIsTakenAgain(): void
    {
        $this->recordings->rememberEpisode('EP01721107.2622', 'The Tonight Show', 'S14E07');

        $this->assertSame(['EP01721107.2622' => true], array_map(
            static fn ($at): bool => $at > 0,
            $this->recordings->recordedEpisodes(['EP01721107.2622'])
        ));

        $response = $this->send('DELETE', '/api/recordings/episodes/EP01721107.2622');

        $this->assertSame(200, $response['status']);
        $this->assertTrue($response['body']['forgotten']);
        $this->assertSame([], $this->recordings->recordedEpisodes(['EP01721107.2622']));
    }

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
