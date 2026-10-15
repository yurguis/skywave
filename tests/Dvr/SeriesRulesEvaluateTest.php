<?php

namespace Skywave\Tests\Dvr;

use PDO;
use PHPUnit\Framework\TestCase;
use Skywave\Dvr\RecordingStore;
use Skywave\Dvr\SeriesRules;
use Skywave\Guide\GuideStore;

/**
 * The evaluation pass itself: what a standing rule does to the guide in front of it.
 *
 * Channels go in through GuideStore's own API. Events do not: saveChannelGuide takes the
 * shape a broadcast parser emits, several layers deep, so they are inserted directly here
 * rather than building a fake transport stream to test a scheduling rule.
 */
class SeriesRulesEvaluateTest extends TestCase
{
    private const DEVICE = '192.168.1.62';

    private string $directory;

    private string $path;

    private GuideStore $guide;

    private RecordingStore $recordings;

    private SeriesRules $series;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/skywave-evaluate-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
        $this->path       = $this->directory . '/guide.sqlite';
        $this->guide      = new GuideStore($this->path);
        $this->recordings = new RecordingStore($this->path);
        $this->series     = new SeriesRules($this->guide, $this->recordings);

        $this->guide->saveLineup(self::DEVICE, [
            ['physical' => 29, 'program' => 3, 'virtual' => '6.1', 'name' => 'WTVJ', 'tsid' => 627, 'encrypted' => false, 'hd' => true],
            ['physical' => 31, 'program' => 1, 'virtual' => '7.1', 'name' => 'WSVN', 'tsid' => 628, 'encrypted' => false, 'hd' => true],
        ]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->directory);
    }

    public function testAMatchingShowingIsScheduled(): void
    {
        $this->addEvent(29, 3, 'Jeopardy!', time() + 3600);
        $this->addRule('Jeopardy!');

        $result = $this->series->evaluate(self::DEVICE);

        $this->assertSame(1, $result['scheduled']);
        $this->assertCount(1, $this->recordings->getSchedules());
        $this->assertSame('Jeopardy!', $this->recordings->getSchedules()[0]['title']);
    }

    public function testAnotherTitleIsLeftAlone(): void
    {
        $this->addEvent(29, 3, 'Wheel of Fortune', time() + 3600);
        $this->addRule('Jeopardy!');

        $this->assertSame(0, $this->series->evaluate(self::DEVICE)['scheduled']);
        $this->assertSame([], $this->recordings->getSchedules());
    }

    public function testTheSameTitleOnAnotherChannelIsLeftAlone(): void
    {
        // The rule names a channel; a different one showing the same thing is not it.
        $this->addEvent(31, 1, 'Jeopardy!', time() + 3600);
        $this->addRule('Jeopardy!');

        $this->assertSame(0, $this->series->evaluate(self::DEVICE)['scheduled']);
    }

    public function testEvaluatingTwiceSchedulesOnce(): void
    {
        $this->addEvent(29, 3, 'Jeopardy!', time() + 3600);
        $this->addRule('Jeopardy!');

        $this->series->evaluate(self::DEVICE);

        $this->assertSame(0, $this->series->evaluate(self::DEVICE)['scheduled']);
        $this->assertCount(1, $this->recordings->getSchedules());
    }

    public function testSomethingAlreadyRecordedIsNotScheduledAgain(): void
    {
        // The guide keeps a programme for hours after it aired. addSchedule would replace a
        // finished one and set it back to "scheduled", so the pass has to skip it outright.
        $start = time() + 3600;
        $this->addEvent(29, 3, 'Jeopardy!', $start);
        $this->addRule('Jeopardy!');

        $id = $this->recordings->addSchedule([
            'device'      => self::DEVICE,
            'physical'    => 29,
            'program'     => 3,
            'virtual'     => '6.1',
            'channelName' => 'WTVJ',
            'start'       => $start,
            'duration'    => 1800,
            'title'       => 'Jeopardy!',
        ]);
        $this->recordings->markSchedule($id, RecordingStore::STATUS_DONE);

        $this->assertSame(0, $this->series->evaluate(self::DEVICE)['scheduled']);
        $this->assertSame(RecordingStore::STATUS_DONE, $this->recordings->getSchedule($id)['status']);
    }

    public function testOneRuleWillNotScheduleTheWholeAfternoon(): void
    {
        // Some channels run the same programme a dozen times over; one press should not
        // take every tuner. The rest wait for the next pass.
        for ($i = 0; $i < 12; $i++) {
            $this->addEvent(29, 3, 'Cheaters', time() + 3600 + $i * 1800, 1800, 500 + $i);
        }

        $this->addRule('Cheaters');

        $this->assertSame(8, $this->series->evaluate(self::DEVICE)['scheduled']);
        $this->assertCount(8, $this->recordings->getSchedules());
    }

    public function testAnInactiveRuleDoesNothing(): void
    {
        $this->addEvent(29, 3, 'Jeopardy!', time() + 3600);
        $id = $this->addRule('Jeopardy!');

        $this->deactivate($id);

        $this->assertSame(0, $this->series->evaluate(self::DEVICE)['rules']);
        $this->assertSame(0, $this->series->evaluate(self::DEVICE)['scheduled']);
    }

    public function testAShowingKeepsRecordingAsLongAsItsRuleSays(): void
    {
        // The reason a rule carries this at all: every showing of a series that runs over
        // needs the same margin, and saying so once is the only practical way.
        $this->addEvent(29, 3, '2026 MLB Playoffs', time() + 3600);
        $this->addRule('2026 MLB Playoffs', 30 * 60);

        $this->series->evaluate(self::DEVICE);

        $this->assertSame(30 * 60, $this->recordings->getSchedules()[0]['padEnd']);
    }

    private function addRule(string $title, int $padEnd = 180, bool $newOnly = false): int
    {
        return $this->recordings->addRule([
            'device'      => self::DEVICE,
            'physical'    => 29,
            'program'     => 3,
            'virtual'     => '6.1',
            'channelName' => 'WTVJ',
            'title'       => $title,
            'timezone'    => 'UTC',
            'padStart'    => 60,
            'padEnd'      => $padEnd,
            'newOnly'     => $newOnly,
        ]);
    }

    public function testAnEpisodeAlreadyRecordedIsLeftAlone(): void
    {
        // The case this exists for: a week of repeats of a programme already on the drive.
        $now = time();
        $this->addRule('The Tonight Show');
        $this->recordings->rememberEpisode('EP01721107.2622', 'The Tonight Show', 'S14E07');

        $this->addEvent(29, 3, 'The Tonight Show', $now + 3600, 3600, 10, 'EP01721107.2622');
        $this->addEvent(29, 3, 'The Tonight Show', $now + 90000, 3600, 11, 'EP01721107.2699');

        $result = $this->series->evaluate(self::DEVICE, $now);

        $this->assertSame(1, $result['scheduled'], 'only the one not already recorded');
        $this->assertSame(1, $result['hadAlready']);

        $scheduled = $this->recordings->getSchedules(self::DEVICE);

        $this->assertCount(1, $scheduled);
        $this->assertSame('EP01721107.2699', $scheduled[0]['programmeId']);
    }

    public function testAShowingWithNoIdentityIsStillTaken(): void
    {
        // A channel with no online guide behind it says nothing about episodes. Nothing is
        // known, so nothing is skipped, and the rule behaves as it always did.
        $now = time();
        $this->addRule('The Tonight Show');
        $this->recordings->rememberEpisode('EP01721107.2622', 'The Tonight Show', 'S14E07');

        $this->addEvent(29, 3, 'The Tonight Show', $now + 3600, 3600, 10);

        $this->assertSame(1, $this->series->evaluate(self::DEVICE, $now)['scheduled']);
    }

    public function testARuleForNewShowingsPassesOverRepeats(): void
    {
        $now = time();
        $this->addRule('The Tonight Show', 180, true);

        $this->addEvent(29, 3, 'The Tonight Show', $now + 3600, 3600, 10, 'EP01.1', true);
        $this->addEvent(29, 3, 'The Tonight Show', $now + 90000, 3600, 11, 'EP01.2', false);

        $result = $this->series->evaluate(self::DEVICE, $now);

        $this->assertSame(1, $result['scheduled']);
        $this->assertSame(1, $result['repeats']);
        $this->assertSame('EP01.2', $this->recordings->getSchedules(self::DEVICE)[0]['programmeId']);
    }

    public function testAnOrdinaryRuleStillTakesRepeats(): void
    {
        // Only a rule that asked for first showings passes them over.
        $now = time();
        $this->addRule('The Tonight Show');
        $this->addEvent(29, 3, 'The Tonight Show', $now + 3600, 3600, 10, 'EP01.1', true);

        $this->assertSame(1, $this->series->evaluate(self::DEVICE, $now)['scheduled']);
    }

    public function testDeletingARecordingDoesNotBringTheEpisodeBack(): void
    {
        // Deleting is for space or because it has been watched. Neither means it is wanted
        // again, so what was recorded is remembered apart from the recording.
        $now = time();
        $id  = $this->recordings->addRecording([
            'scheduleId'  => null, 'device' => self::DEVICE, 'physical' => 29, 'program' => 3,
            'virtual'     => '6.1', 'channelName' => 'WTVJ', 'title' => 'The Tonight Show',
            'programmeId' => 'EP01721107.2622', 'episode' => 'S14E07',
            'path'        => 'x.ts', 'format' => 'both', 'tuner' => 0, 'pid' => 1,
            'startedAt'   => $now - 7200, 'stopsAt' => $now - 3600,
        ]);
        $this->recordings->rememberEpisode('EP01721107.2622', 'The Tonight Show', 'S14E07');
        $this->recordings->deleteRecording($id);

        $this->addRule('The Tonight Show');
        $this->addEvent(29, 3, 'The Tonight Show', $now + 3600, 3600, 10, 'EP01721107.2622');

        $this->assertSame(0, $this->series->evaluate(self::DEVICE, $now)['scheduled']);
        $this->assertSame([], $this->recordings->getSchedules(self::DEVICE));
    }

    public function testForgettingAnEpisodeLetsARuleTakeItAgain(): void
    {
        $now = time();
        $this->recordings->rememberEpisode('EP01721107.2622', 'The Tonight Show', 'S14E07');
        $this->addRule('The Tonight Show');
        $this->addEvent(29, 3, 'The Tonight Show', $now + 3600, 3600, 10, 'EP01721107.2622');

        $this->assertTrue($this->recordings->forgetEpisode('EP01721107.2622'));
        $this->assertSame(1, $this->series->evaluate(self::DEVICE, $now)['scheduled']);
    }

    private function addEvent(int $physical, int $program, string $title, int $start, int $duration = 1800, int $eventId = 42, ?string $programmeId = null, bool $repeat = false): void
    {
        $db = new PDO('sqlite:' . $this->path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        $find = $db->prepare('SELECT id FROM channels WHERE device = ? AND physical = ? AND program = ?');
        $find->execute([self::DEVICE, $physical, $program]);

        $insert = $db->prepare(
            'INSERT OR REPLACE INTO events (channel_id, event_id, start, duration, title, rating, description, programme_id, is_repeat, updated_at)
             VALUES (?, ?, ?, ?, ?, NULL, NULL, ?, ?, ?)'
        );
        $insert->execute([(int) $find->fetchColumn(), $eventId, $start, $duration, $title, $programmeId, $repeat ? 1 : 0, time()]);
    }

    private function deactivate(int $id): void
    {
        $db = new PDO('sqlite:' . $this->path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->prepare('UPDATE rules SET active = 0 WHERE id = ?')->execute([$id]);
    }
}
