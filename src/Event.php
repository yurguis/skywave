<?php declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave;

/**
 * A single program-guide event from an EIT section.
 *
 * `startTime` is GPS seconds since 1980-01-06 UTC (same epoch as
 * SystemTimeTable::system_time). Use SystemTimeTable::GPS_EPOCH_UNIX
 * and the STT-provided GPS-UTC offset to render as UTC.
 */
class Event
{
    private int $eventId;
    private int $startTime;
    private int $lengthSeconds;
    private string $title;
    /** @var object[] keyed by descriptor name */
    private array $descriptors;

    /**
     * @param object[] $descriptors keyed by descriptor name
     */
    public function __construct(int $eventId, int $startTime, int $lengthSeconds, string $title, array $descriptors = [])
    {
        $this->eventId       = $eventId;
        $this->startTime     = $startTime;
        $this->lengthSeconds = $lengthSeconds;
        $this->title         = $title;
        $this->descriptors   = $descriptors;
    }

    /** @return object[] */
    public function getDescriptors(): array
    {
        return $this->descriptors;
    }

    public function getEventId(): int
    {
        return $this->eventId;
    }

    public function getStartTime(): int
    {
        return $this->startTime;
    }

    public function getLengthSeconds(): int
    {
        return $this->lengthSeconds;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getUtcTimestamp(int $gpsUtcOffset): int
    {
        return $this->startTime + SystemTimeTable::GPS_EPOCH_UNIX - $gpsUtcOffset;
    }
}
