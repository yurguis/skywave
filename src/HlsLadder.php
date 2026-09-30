<?php

declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave;

/**
 * The filter graph, output arguments and variant map for an HLS ladder.
 *
 * One picture encoded at several sizes, so a player can drop to a smaller one when the
 * connection tightens rather than stalling. Built here as plain arrays rather than run, so
 * the shape can be read back in a test: checking it otherwise needs ffmpeg on the machine
 * running the tests, and a process this one may not be able to detach.
 *
 * LiveStreams builds the same thing for live playback and still has its own copy. Moving it
 * here would be a change to the path that works, tested by nothing, so it is left alone
 * until there is a reason to touch it.
 */
final class HlsLadder
{
    /**
     * Highest video bitrate for a picture height, in kbit/s.
     *
     * A busy scene cannot outgrow the connection its size was chosen for; quieter pictures
     * use less than this and are left alone.
     */
    public static function maxBitrate(int $height): int
    {
        if ($height > 720) {
            return 7000;
        }

        if ($height > 540) {
            return 3500;
        }

        return $height > 360 ? 1500 : ($height > 240 ? 800 : 450);
    }

    /**
     * @param int[]                      $renditions   picture heights, tallest first
     * @param list<array<string, mixed>> $tracks       audio tracks, in the order ffmpeg maps them
     * @param int                        $defaultTrack which of them to offer first
     * @param string                     $input        the video stream to read, as ffmpeg names it
     * @return array{0: string[], 1: string[], 2: string[]} filter graph, output arguments, variant map
     */
    public static function plan(array $renditions, array $tracks, int $defaultTrack = 0, string $input = '0:v:0'): array
    {
        $renditions = array_values($renditions);
        $top        = $renditions[0];
        $count      = count($renditions);

        // Deinterlace once, then scale a copy per size. estdif works from a single frame, so
        // the captions that travel with each one survive; a temporal deinterlacer scrambles
        // them. Splitting after it means the sizes share the expensive part instead of each
        // paying for it separately.
        $graph = ["[$input]estdif=mode=frame:deint=interlaced,split=$count"
            . implode('', array_map(static fn (int $i): string => "[s$i]", array_keys($renditions)))];

        $outputs = [];
        $streams = [];
        // Several tracks share one audio group, which is also what stops the same track
        // being encoded once per size.
        $shared = count($tracks) > 1;

        foreach ($renditions as $i => $height) {
            // Smaller sizes shrink in proportion to the source, so a standard definition
            // recording gets 480/320/240 rather than three copies of 480.
            $graph[]   = sprintf('[s%d]scale=w=-2:h=trunc(min(%d\,ih*%d/%d)/2)*2[v%d]', $i, $height, $height, $top, $i);
            $outputs[] = '-map';
            $outputs[] = "[v$i]";

            // Only worth capping when there is another size to fall back to. With one, the
            // cap would lower the quality of the only picture on offer.
            if ($count > 1) {
                $rate    = self::maxBitrate($height);
                $outputs = array_merge($outputs, ["-maxrate:v:$i", "{$rate}k", "-bufsize:v:$i", ($rate * 2) . 'k']);
            }

            $streams[] = $shared ? "v:$i,agroup:aud" : "v:$i,a:$i";

            if (!$shared) {
                $outputs = array_merge($outputs, ['-map', '0:a:0', "-b:a:$i", $height >= 480 ? '128k' : '96k']);
            }
        }

        foreach ($shared ? $tracks : [] as $i => $track) {
            $outputs   = array_merge($outputs, ['-map', "0:a:$i", "-b:a:$i", '128k']);
            $language  = $track['language'] ?? null;
            $streams[] = "a:$i,agroup:aud"
                . ($language === null ? '' : ",language:$language")
                . ($i === $defaultTrack ? ',default:yes' : '');
        }

        return [$graph, $outputs, $streams];
    }
}
