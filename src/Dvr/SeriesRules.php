<?php

declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave\Dvr;

use DateTimeImmutable;
use DateTimeZone;
use Skywave\Guide\GuideStore;

/**
 * Standing rules, turned into schedules whenever the guide is refreshed.
 *
 * A broadcast carries about twelve hours of guide, so nothing can be scheduled a week
 * ahead. Rules are evaluated instead: whatever has come into view and matches is scheduled
 * then and there. A listing that changes next week can never be wrong, because nothing was
 * ever claimed about next week.
 *
 * ATSC has no series identifier and no repeat flag, and barely a twentieth of events carry
 * a description, so a rule can only match a title on a channel. The time of day and the
 * weekday are what narrow it: "Jeopardy! on 6.1 between 18:00 and 20:00" takes the evening
 * showing and leaves the small-hours repeat alone.
 */
class SeriesRules
{
    /** As far ahead as any broadcast is likely to describe itself. */
    private const WINDOW_SECONDS = 3 * 86400;

    /**
     * How much one rule may schedule in a single pass. Some titles air twenty or thirty
     * times in the window — a channel running the same programme all afternoon — and one
     * press of "all episodes" should not take every tuner and fill the drive.
     */
    private const MAX_PER_EVALUATION = 8;

    private GuideStore $guide;

    private RecordingStore $recordings;

    public function __construct(GuideStore $guide, RecordingStore $recordings)
    {
        $this->guide      = $guide;
        $this->recordings = $recordings;
    }

    /**
     * Schedule everything in view that a rule asks for.
     *
     * @return array{rules: int, scheduled: int}
     */
    public function evaluate(?string $device = null, ?int $now = null): array
    {
        $now   = $now ?? time();
        $rules = $this->recordings->getRules($device, true);

        if ($rules === []) {
            return ['rules' => 0, 'scheduled' => 0];
        }

        $guide     = $this->guide->getGuide($now, $now + self::WINDOW_SECONDS, $device);
        $scheduled = 0;

        foreach ($rules as $rule) {
            $fromThisRule = 0;

            foreach ($guide as $channel) {
                if (!self::sameChannel($rule, $channel)) {
                    continue;
                }

                foreach ($channel['events'] as $event) {
                    if (!self::matches($rule, $event, $now)) {
                        continue;
                    }

                    // Any schedule at all, whatever became of it. addSchedule replaces a
                    // finished one and would set it back to "scheduled", so a programme
                    // recorded this morning must not be picked up again while it is still
                    // in the guide.
                    if ($this->recordings->findSchedule($rule['device'], $rule['physical'], $rule['program'], (int) $event['start']) !== null) {
                        continue;
                    }

                    if ($fromThisRule >= self::MAX_PER_EVALUATION) {
                        // The rest stay for the next pass, by which time the earlier ones
                        // have been recorded and no longer count against the limit.
                        break 2;
                    }

                    $this->recordings->addSchedule([
                        'device'      => $rule['device'],
                        'physical'    => $rule['physical'],
                        'program'     => $rule['program'],
                        'virtual'     => $rule['virtual'],
                        'channelName' => $rule['channelName'],
                        'eventId'     => $event['eventId'] ?? null,
                        'start'       => (int) $event['start'],
                        'duration'    => (int) $event['duration'],
                        'title'       => $event['title'],
                        'description' => $event['description'] ?? null,
                        'padStart'    => $rule['padStart'],
                        'padEnd'      => $rule['padEnd'],
                        'format'      => $rule['format'],
                    ]);
                    $fromThisRule++;
                    $scheduled++;
                }
            }
        }

        return ['rules' => count($rules), 'scheduled' => $scheduled];
    }

    /**
     * @param array<string, mixed> $rule
     * @param array<string, mixed> $channel
     */
    private static function sameChannel(array $rule, array $channel): bool
    {
        return $channel['device'] === $rule['device']
            && (int) $channel['physical'] === $rule['physical']
            && (int) $channel['program'] === $rule['program'];
    }

    /**
     * Whether one showing is what a rule asked for.
     *
     * @param array<string, mixed> $rule
     * @param array<string, mixed> $event
     */
    public static function matches(array $rule, array $event, int $now): bool
    {
        // Already over: the recorder would only mark it missed.
        if ((int) $event['start'] + (int) $event['duration'] <= $now) {
            return false;
        }

        // Broadcasters are consistent about their own titles, but not about their capitals.
        if (strcasecmp(trim((string) $event['title']), trim((string) $rule['title'])) !== 0) {
            return false;
        }

        $zone = self::zone((string) ($rule['timezone'] ?? 'UTC'));
        $when = (new DateTimeImmutable('@' . (int) $event['start']))->setTimezone($zone);

        if (!self::onChosenDay($rule, $when)) {
            return false;
        }

        return self::inChosenHours($rule, $when);
    }

    private static function zone(string $name): DateTimeZone
    {
        // A rule carries the zone of whoever wrote it: the container runs on UTC, and an
        // evening in New York is the small hours here.
        // Throwable, not Exception: PHP 8.3 raises DateInvalidTimeZoneException here, and a
        // stored zone that has gone bad must cost one rule its window, not the whole pass.
        try {
            return new DateTimeZone($name === '' ? 'UTC' : $name);
        } catch (\Throwable $e) {
            return new DateTimeZone('UTC');
        }
    }

    /**
     * @param array<string, mixed> $rule
     */
    private static function onChosenDay(array $rule, DateTimeImmutable $when): bool
    {
        $days = (string) ($rule['days'] ?? '');

        if (trim($days) === '') {
            return true;
        }

        $wanted = array_map('intval', array_filter(array_map('trim', explode(',', $days)), 'strlen'));

        return $wanted === [] || in_array((int) $when->format('N'), $wanted, true);
    }

    /**
     * @param array<string, mixed> $rule
     */
    private static function inChosenHours(array $rule, DateTimeImmutable $when): bool
    {
        $earliest = $rule['earliest'] ?? null;
        $latest   = $rule['latest'] ?? null;

        if ($earliest === null || $latest === null) {
            return true;
        }

        $minutes = (int) $when->format('G') * 60 + (int) $when->format('i');

        // A window that ends before it starts runs over midnight: 23:00 to 01:00.
        if ((int) $earliest <= (int) $latest) {
            return $minutes >= (int) $earliest && $minutes <= (int) $latest;
        }

        return $minutes >= (int) $earliest || $minutes <= (int) $latest;
    }
}
