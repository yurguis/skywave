<?php declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave\Hdhomerun;

use InvalidArgumentException;

/**
 * Channel number to center frequency tables for every channel map HDHomeRun
 * firmware understands, ported from libhdhomerun's hdhomerun_channels.c.
 */
class ChannelMap
{
    /** Frequencies are rounded to the tuner's 125 kHz resolution. */
    private const FREQUENCY_RESOLUTION = 125000;

    /** [first channel, last channel, frequency of first channel (Hz), spacing (Hz)] */
    private const RANGES_AU_BCAST = [[5, 12, 177500000, 7000000], [21, 69, 480500000, 7000000]];
    private const RANGES_EU_BCAST = [[5, 12, 177500000, 7000000], [21, 69, 474000000, 8000000]];
    /** No common standard: the channel number is the frequency in MHz. */
    private const RANGES_EU_CABLE = [[108, 862, 108000000, 1000000]];
    private const RANGES_KR_CABLE = [
        [2, 4, 57000000, 6000000], [5, 6, 79000000, 6000000], [7, 13, 177000000, 6000000],
        [14, 22, 123000000, 6000000], [23, 153, 219000000, 6000000],
    ];
    private const RANGES_JP_BCAST = [[13, 62, 473000000, 6000000]];
    private const RANGES_TW_BCAST = [[7, 13, 177000000, 6000000], [14, 69, 473000000, 6000000]];
    /** Channel 37 is reserved for radio astronomy. */
    private const RANGES_US_BCAST = [
        [2, 4, 57000000, 6000000], [5, 6, 79000000, 6000000], [7, 13, 177000000, 6000000],
        [14, 36, 473000000, 6000000], [38, 51, 617000000, 6000000],
    ];
    private const RANGES_US_CABLE = [
        [2, 4, 57000000, 6000000], [5, 6, 79000000, 6000000], [7, 13, 177000000, 6000000],
        [14, 22, 123000000, 6000000], [23, 94, 219000000, 6000000], [95, 99, 93000000, 6000000],
        [100, 158, 651000000, 6000000],
    ];
    private const RANGES_US_HRC = [
        [2, 4, 55752700, 6000300], [5, 6, 79753900, 6000300], [7, 13, 175758700, 6000300],
        [14, 22, 121756000, 6000300], [23, 94, 217760800, 6000300], [95, 99, 91754500, 6000300],
        [100, 158, 649782400, 6000300],
    ];
    private const RANGES_US_IRC = [
        [2, 4, 57012500, 6000000], [5, 6, 81012500, 6000000], [7, 13, 177012500, 6000000],
        [14, 22, 123012500, 6000000], [23, 41, 219012500, 6000000], [42, 42, 333025000, 6000000],
        [43, 94, 339012500, 6000000], [95, 97, 93012500, 6000000], [98, 99, 111025000, 6000000],
        [100, 158, 651012500, 6000000],
    ];

    private const MAPS = [
        'au-bcast' => self::RANGES_AU_BCAST,
        'au-cable' => self::RANGES_EU_CABLE,
        'eu-bcast' => self::RANGES_EU_BCAST,
        'eu-cable' => self::RANGES_EU_CABLE,
        'tw-bcast' => self::RANGES_TW_BCAST,
        'tw-cable' => self::RANGES_US_CABLE,
        'kr-bcast' => self::RANGES_US_BCAST,
        'kr-cable' => self::RANGES_KR_CABLE,
        'us-bcast' => self::RANGES_US_BCAST,
        'us-cable' => self::RANGES_US_CABLE,
        'us-hrc'   => self::RANGES_US_HRC,
        'us-irc'   => self::RANGES_US_IRC,
        'jp-bcast' => self::RANGES_JP_BCAST,
    ];

    /**
     * @return string[]
     */
    public static function getNames(): array
    {
        return array_keys(self::MAPS);
    }

    public static function exists(string $map): bool
    {
        return isset(self::MAPS[$map]);
    }

    /**
     * @return array<int, int> channel number => frequency in Hz, ordered by channel number
     */
    public static function getChannels(string $map): array
    {
        if (!self::exists($map)) {
            throw new InvalidArgumentException("Unknown channel map: $map");
        }

        $channels = [];

        foreach (self::MAPS[$map] as [$first, $last, $frequency, $spacing]) {
            for ($channel = $first; $channel <= $last; $channel++) {
                $channels[$channel] = self::round($frequency + ($channel - $first) * $spacing);
            }
        }

        ksort($channels);

        return $channels;
    }

    public static function getFrequency(string $map, int $channel): ?int
    {
        return self::getChannels($map)[$channel] ?? null;
    }

    public static function getChannelNumber(string $map, int $frequency): ?int
    {
        $channel = array_search(self::round($frequency), self::getChannels($map), true);

        return $channel === false ? null : $channel;
    }

    private static function round(int $frequency): int
    {
        return intdiv($frequency + intdiv(self::FREQUENCY_RESOLUTION, 2), self::FREQUENCY_RESOLUTION) * self::FREQUENCY_RESOLUTION;
    }
}
