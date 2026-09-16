<?php

declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Skywave\Io\BinaryReader;

/**
 * ATSC A/65 §6.1 - System Time Table.
 *
 * Carries the wall-clock reference used by receivers for the EPG.
 * `system_time` is seconds since the GPS epoch (1980-01-06 00:00:00 UTC);
 * `gpsUtcOffset` is the current GPS-minus-UTC offset (leap seconds).
 */
class SystemTimeTable extends SectionTable
{
    /** Unix timestamp of 1980-01-06 00:00:00 UTC, the GPS epoch. */
    public const GPS_EPOCH_UNIX = 315964800;

    private int $systemTime   = 0;
    private int $gpsUtcOffset = 0;
    private bool $dsStatus    = false;
    private int $dsDayOfMonth = 0;
    private int $dsHour       = 0;

    public function parse(): void
    {
        $reader = new BinaryReader($this->buffer);

        // table_id_extension(2) + flags/version/cni(1) + section_number(1)
        // + last_section_number(1) + protocol_version(1)
        $reader->skipBytes(6);

        $this->systemTime   = $reader->uint32();
        $this->gpsUtcOffset = $reader->uint8();

        $this->dsStatus = $reader->bits(1) === 1;
        $reader->skipBits(2); // reserved
        $this->dsDayOfMonth = $reader->bits(5);
        $this->dsHour       = $reader->uint8();

        // Trailing descriptors + CRC_32 are not surfaced.
    }

    public function getSystemTime(): int
    {
        return $this->systemTime;
    }

    public function getUtcTimestamp(): int
    {
        return $this->systemTime + self::GPS_EPOCH_UNIX - $this->gpsUtcOffset;
    }

    /**
     * @throws Exception
     */
    public function getUtcDateTime(): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . $this->getUtcTimestamp()))
            ->setTimezone(new DateTimeZone('UTC'));
    }

    public function getGpsUtcOffset(): int
    {
        return $this->gpsUtcOffset;
    }

    public function isDaylightSavings(): bool
    {
        return $this->dsStatus;
    }

    public function getDsDayOfMonth(): int
    {
        return $this->dsDayOfMonth;
    }

    public function getDsHour(): int
    {
        return $this->dsHour;
    }
}
