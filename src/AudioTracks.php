<?php

declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave;

/**
 * The audio tracks a recording carries, as the player needs to hear about them.
 *
 * A broadcast names its tracks poorly or not at all: two English tracks where the second is
 * an audio description, a Spanish track with no language tag, 5.1 and stereo side by side.
 * What is read here is what the page puts in the menu, and which track a converted copy
 * offers first depends on it.
 *
 * Extracted from RecordingPlayback so the recorder can plan the same ladder when it converts
 * ahead of time, and so the shape can be read back in a test.
 */
final class AudioTracks
{
    /** More languages than this on one programme is not something to plan for. */
    public const LIMIT = 4;

    /**
     * @return list<array{language: string|null, channels: string|null, described: bool}>
     */
    public static function of(string $ffmpeg, string $file): array
    {
        $probe = str_replace('ffmpeg', 'ffprobe', $ffmpeg);
        $shown = (string) shell_exec(sprintf(
            '%s -v error -select_streams a -show_entries stream=channels:stream_disposition=visual_impaired:stream_tags=language -of json %s 2>/dev/null',
            escapeshellarg($probe),
            escapeshellarg($file)
        ));

        return self::read($shown);
    }

    /**
     * The tracks ffprobe's JSON describes. Split out from the probe itself so the reading
     * can be tested without ffprobe on the machine running the tests.
     *
     * @return list<array{language: string|null, channels: string|null, described: bool}>
     */
    public static function read(string $json): array
    {
        $probed = json_decode($json, true);
        $tracks = [];

        foreach (array_slice($probed['streams'] ?? [], 0, self::LIMIT) as $stream) {
            $count = isset($stream['channels']) ? (int) $stream['channels'] : null;

            $tracks[] = [
                'language' => $stream['tags']['language'] ?? null,
                'channels' => $count === null ? null : self::channelLabel($count),
                // An audio description is a second track in the same language, and the
                // broadcast marks it rather than naming it. Without reading the mark both
                // read as plain English and whichever came first won for good.
                'described' => (bool) ($stream['disposition']['visual_impaired'] ?? false),
            ];
        }

        return $tracks;
    }

    /**
     * Which track to offer first: the first one that is not an audio description.
     *
     * @param list<array<string, mixed>> $tracks
     */
    public static function preferred(array $tracks): int
    {
        foreach ($tracks as $index => $track) {
            if (!($track['described'] ?? false)) {
                return $index;
            }
        }

        return 0;
    }

    private static function channelLabel(int $channels): string
    {
        return $channels > 2 ? sprintf('%d.1', $channels - 1) : sprintf('%d.0', $channels);
    }
}
