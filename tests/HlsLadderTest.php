<?php

namespace Skywave\Tests;

use PHPUnit\Framework\TestCase;
use Skywave\HlsLadder;

/**
 * One picture at several sizes, read back as text.
 *
 * Running any of this needs ffmpeg on the machine and a process it can detach, neither of
 * which a test can count on, so the ladder is built as arrays and checked as arrays.
 */
class HlsLadderTest extends TestCase
{
    public function testOneSizeIsStillOneVideoAndOneAudio(): void
    {
        [$graph, $outputs, $streams] = HlsLadder::plan([720], [['language' => 'eng']]);

        $this->assertSame('[0:v:0]estdif=mode=frame:deint=interlaced,split=1[s0]', $graph[0]);
        $this->assertSame(['v:0,a:0'], $streams);
        $this->assertContains('-map', $outputs);
        $this->assertContains('[v0]', $outputs);
    }

    public function testASingleSizeIsNotCapped(): void
    {
        // With nothing to fall back to, a cap would only lower the one picture on offer.
        [, $outputs] = HlsLadder::plan([720], [['language' => 'eng']]);

        $this->assertNotContains('-maxrate:v:0', $outputs);
    }

    public function testEverySizeGetsItsOwnScaleAndCap(): void
    {
        [$graph, $outputs, $streams] = HlsLadder::plan([720, 480, 360], [['language' => 'eng']]);

        $this->assertSame('[0:v:0]estdif=mode=frame:deint=interlaced,split=3[s0][s1][s2]', $graph[0]);
        $this->assertCount(4, $graph, 'the split, then one scale per size');
        $this->assertSame(['v:0,a:0', 'v:1,a:1', 'v:2,a:2'], $streams);

        $this->assertContains('-maxrate:v:0', $outputs);
        $this->assertContains('3500k', $outputs);
        $this->assertContains('800k', $outputs);
    }

    public function testSmallerSizesShrinkInProportionToTheRecording(): void
    {
        // Otherwise a standard definition recording is encoded as three copies of 480
        // rather than 480/320/240, and the lower sizes cost the same as the top one.
        [$graph] = HlsLadder::plan([720, 480], [['language' => 'eng']]);

        $this->assertSame('[s0]scale=w=-2:h=trunc(min(720\,ih*720/720)/2)*2[v0]', $graph[1]);
        $this->assertSame('[s1]scale=w=-2:h=trunc(min(480\,ih*480/720)/2)*2[v1]', $graph[2]);
    }

    public function testSeveralLanguagesShareOneAudioGroup(): void
    {
        // Every size pointing at the same group is what stops a track being encoded once
        // per size, and what lets the player offer the languages against any of them.
        $tracks = [['language' => 'eng'], ['language' => 'spa']];

        [, $outputs, $streams] = HlsLadder::plan([720, 480], $tracks);

        $this->assertSame([
            'v:0,agroup:aud',
            'v:1,agroup:aud',
            'a:0,agroup:aud,language:eng,default:yes',
            'a:1,agroup:aud,language:spa',
        ], $streams);

        // Two sizes and two tracks, but each track mapped once.
        $this->assertSame(1, self::occurrences($outputs, '0:a:0'));
        $this->assertSame(1, self::occurrences($outputs, '0:a:1'));
    }

    public function testTheChosenTrackIsTheDefaultOne(): void
    {
        // An audio description comes first in some broadcasts, and the default has to be
        // the track a viewer expects rather than whichever ffmpeg happened to map first.
        $tracks = [['language' => 'eng'], ['language' => 'eng']];

        [, , $streams] = HlsLadder::plan([720, 480], $tracks, 1);

        $this->assertSame('a:0,agroup:aud,language:eng', $streams[2]);
        $this->assertSame('a:1,agroup:aud,language:eng,default:yes', $streams[3]);
    }

    public function testATrackWithNoLanguageIsStillOffered(): void
    {
        [, , $streams] = HlsLadder::plan([720], [['language' => null], ['language' => null]]);

        $this->assertSame('a:0,agroup:aud,default:yes', $streams[1]);
    }

    public function testTheBitrateCapFollowsThePictureSize(): void
    {
        $this->assertSame(7000, HlsLadder::maxBitrate(1080));
        $this->assertSame(3500, HlsLadder::maxBitrate(720));
        $this->assertSame(1500, HlsLadder::maxBitrate(480));
        $this->assertSame(800, HlsLadder::maxBitrate(360));
        $this->assertSame(450, HlsLadder::maxBitrate(240));
    }

    /**
     * @param string[] $arguments
     */
    private static function occurrences(array $arguments, string $value): int
    {
        return count(array_keys($arguments, $value, true));
    }
}
