<?php

namespace Skywave\Tests\Dvr;

use PHPUnit\Framework\TestCase;
use Skywave\Dvr\Recorder;

/**
 * Which sizes a browser-ready playlist is made at.
 *
 * A height chosen by hand on the Convert button says how big the picture should be. The
 * smaller sizes below it are still made: they are what a player drops to when the connection
 * tightens, which is most of the reason for making a playlist rather than a file.
 */
class ConversionLadderTest extends TestCase
{
    public function testNoChoiceMakesTheWholeLadder(): void
    {
        $this->assertSame([1080, 720, 480], Recorder::ladderUpTo([1080, 720, 480], null));
    }

    public function testAChosenHeightCapsItWithoutLosingTheSmallerSizes(): void
    {
        $this->assertSame([720, 480], Recorder::ladderUpTo([1080, 720, 480], 720));
    }

    public function testAHeightSmallerThanAnythingConfiguredIsMadeOnItsOwn(): void
    {
        $this->assertSame([360], Recorder::ladderUpTo([1080, 720, 480], 360));
    }

    public function testAnImpossibleHeightIsBroughtBackIntoRange(): void
    {
        $this->assertSame([144], Recorder::ladderUpTo([1080], 1));
        $this->assertSame([1080], Recorder::ladderUpTo([1080], 100_000));
    }
}
