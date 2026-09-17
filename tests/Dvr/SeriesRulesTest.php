<?php

namespace Skywave\Tests\Dvr;

use PHPUnit\Framework\TestCase;
use Skywave\Dvr\RecordingStore;
use Skywave\Dvr\SeriesRules;

/**
 * What a standing rule does with a guide that only reaches half a day ahead: it matches
 * what is in view, leaves alone what it has already dealt with, and interprets "evening"
 * in the viewer's own time zone rather than the container's.
 */
class SeriesRulesTest extends TestCase
{
    private string $directory;

    private RecordingStore $store;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/skywave-rules-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
        $this->store = new RecordingStore($this->directory . '/guide.sqlite');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->directory);
    }

    public function testARuleComesBackAsItWentIn(): void
    {
        $id = $this->store->addRule($this->rule(['title' => 'Jeopardy!']));

        $rules = $this->store->getRules();

        $this->assertCount(1, $rules);
        $this->assertSame($id, $rules[0]['id']);
        $this->assertSame('Jeopardy!', $rules[0]['title']);
        $this->assertTrue($rules[0]['active']);
    }

    public function testAskingForTheSameSeriesTwiceMakesOneRule(): void
    {
        $first  = $this->store->addRule($this->rule());
        $second = $this->store->addRule($this->rule());

        $this->assertSame($first, $second);
        $this->assertCount(1, $this->store->getRules());
    }

    public function testDeletingSaysWhetherThereWasOne(): void
    {
        $id = $this->store->addRule($this->rule());

        $this->assertTrue($this->store->deleteRule($id));
        $this->assertFalse($this->store->deleteRule($id));
    }

    public function testATitleMatchesWhateverItsCapitals(): void
    {
        $rule  = $this->rule(['title' => 'Jeopardy!']);
        $now   = time();
        $event = ['title' => 'JEOPARDY!', 'start' => $now + 3600, 'duration' => 1800];

        $this->assertTrue(SeriesRules::matches($rule, $event, $now));
    }

    public function testADifferentTitleIsLeftAlone(): void
    {
        $rule  = $this->rule(['title' => 'Jeopardy!']);
        $now   = time();
        $event = ['title' => 'Wheel of Fortune', 'start' => $now + 3600, 'duration' => 1800];

        $this->assertFalse(SeriesRules::matches($rule, $event, $now));
    }

    public function testAShowingThatHasFinishedIsNotMatched(): void
    {
        $rule  = $this->rule();
        $now   = time();
        $event = ['title' => $rule['title'], 'start' => $now - 7200, 'duration' => 1800];

        $this->assertFalse(SeriesRules::matches($rule, $event, $now));
    }

    public function testTheHourWindowIsReadInTheRulesOwnZone(): void
    {
        // 19:30 in New York, which is 23:30 UTC: a rule written as "the evening" has to
        // mean the viewer's evening, not the container's.
        $start = (new \DateTimeImmutable('2026-09-17 19:30', new \DateTimeZone('America/New_York')))->getTimestamp();
        $event = ['title' => 'Jeopardy!', 'start' => $start, 'duration' => 1800];

        $evening = $this->rule(['title' => 'Jeopardy!', 'earliest' => 18 * 60, 'latest' => 20 * 60, 'timezone' => 'America/New_York']);
        $utc     = $this->rule(['title' => 'Jeopardy!', 'earliest' => 18 * 60, 'latest' => 20 * 60, 'timezone' => 'UTC']);

        $this->assertTrue(SeriesRules::matches($evening, $event, $start - 3600));
        $this->assertFalse(SeriesRules::matches($utc, $event, $start - 3600), 'the same clock time in UTC is 23:30, outside the window');
    }

    public function testTheSmallHoursRepeatIsSkipped(): void
    {
        $start = (new \DateTimeImmutable('2026-09-17 02:00', new \DateTimeZone('America/New_York')))->getTimestamp();
        $event = ['title' => 'Jeopardy!', 'start' => $start, 'duration' => 1800];
        $rule  = $this->rule(['title' => 'Jeopardy!', 'earliest' => 18 * 60, 'latest' => 20 * 60, 'timezone' => 'America/New_York']);

        $this->assertFalse(SeriesRules::matches($rule, $event, $start - 3600));
    }

    public function testAWindowCanRunOverMidnight(): void
    {
        $rule = $this->rule(['earliest' => 23 * 60, 'latest' => 60, 'timezone' => 'UTC']);

        foreach (['23:30' => true, '00:30' => true, '12:00' => false] as $clock => $expected) {
            $start = (new \DateTimeImmutable("2026-09-17 $clock", new \DateTimeZone('UTC')))->getTimestamp();
            $event = ['title' => $rule['title'], 'start' => $start, 'duration' => 1800];

            $this->assertSame($expected, SeriesRules::matches($rule, $event, $start - 3600), "at $clock");
        }
    }

    public function testOnlyTheChosenWeekdaysCount(): void
    {
        // 2026-09-17 is a Thursday, which is 4.
        $start = (new \DateTimeImmutable('2026-09-17 19:00', new \DateTimeZone('UTC')))->getTimestamp();
        $event = ['title' => 'Jeopardy!', 'start' => $start, 'duration' => 1800];

        $weekdays = $this->rule(['title' => 'Jeopardy!', 'days' => '1,2,3,4,5', 'timezone' => 'UTC']);
        $weekends = $this->rule(['title' => 'Jeopardy!', 'days' => '6,7', 'timezone' => 'UTC']);

        $this->assertTrue(SeriesRules::matches($weekdays, $event, $start - 3600));
        $this->assertFalse(SeriesRules::matches($weekends, $event, $start - 3600));
    }

    public function testNoWindowAndNoDaysMeansEveryShowing(): void
    {
        $start = (new \DateTimeImmutable('2026-09-17 03:00', new \DateTimeZone('UTC')))->getTimestamp();
        $event = ['title' => 'Jeopardy!', 'start' => $start, 'duration' => 1800];

        $this->assertTrue(SeriesRules::matches($this->rule(['title' => 'Jeopardy!']), $event, $start - 3600));
    }

    public function testANonsenseTimeZoneFallsBackRatherThanCrashing(): void
    {
        $start = (new \DateTimeImmutable('2026-09-17 19:00', new \DateTimeZone('UTC')))->getTimestamp();
        $event = ['title' => 'Jeopardy!', 'start' => $start, 'duration' => 1800];
        $rule  = $this->rule(['title' => 'Jeopardy!', 'earliest' => 18 * 60, 'latest' => 20 * 60, 'timezone' => 'Nowhere/Fictional']);

        $this->assertTrue(SeriesRules::matches($rule, $event, $start - 3600), 'should read as UTC instead of throwing');
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function rule(array $overrides = []): array
    {
        return $overrides + [
            'device'      => '192.168.1.62',
            'physical'    => 29,
            'program'     => 3,
            'virtual'     => '6.1',
            'channelName' => 'WTVJ',
            'title'       => 'The Late Show',
            'earliest'    => null,
            'latest'      => null,
            'days'        => null,
            'timezone'    => 'UTC',
            'format'      => 'ts',
            'padStart'    => 60,
            'padEnd'      => 180,
        ];
    }
}
