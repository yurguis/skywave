<?php

namespace Skywave\Tests\Guide;

use PHPUnit\Framework\TestCase;
use Skywave\Guide\GuideStore;
use Skywave\Guide\ProgrammeArtwork;

/**
 * Filing pictures by programme title.
 *
 * A title is all a broadcast gives, so matching is exact rather than clever: showing the
 * wrong programme's picture would be worse than showing none. Nothing here reaches the
 * internet; the fetching itself is checked by hand against the real service.
 */
class ProgrammeArtworkTest extends TestCase
{
    private string $directory;

    private ProgrammeArtwork $artwork;

    private GuideStore $store;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/skywave-artwork-' . bin2hex(random_bytes(6));
        mkdir($this->directory . '/artwork', 0777, true);

        $this->artwork = new ProgrammeArtwork($this->directory . '/artwork');
        $this->store   = new GuideStore($this->directory . '/guide.sqlite');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->directory));
    }

    public function testTheSameTitleIsFiledTheSameWay(): void
    {
        $this->assertSame(
            ProgrammeArtwork::key('The Tonight Show'),
            ProgrammeArtwork::key('the tonight show')
        );
    }

    public function testSpacingDoesNotChangeWhereATitleIsFiled(): void
    {
        // A broadcast pads and doubles spaces; it is the same programme.
        $this->assertSame(
            ProgrammeArtwork::key('Antiques Roadshow'),
            ProgrammeArtwork::key('  Antiques   Roadshow ')
        );
    }

    public function testDifferentProgrammesAreFiledApart(): void
    {
        $this->assertNotSame(
            ProgrammeArtwork::key('The Middle'),
            ProgrammeArtwork::key('The Middle East')
        );
    }

    public function testATitleCannotBecomeAPath(): void
    {
        // Titles carry slashes and dots: "Caregiving Shorts (Well Beings/Weta/Sfpbs)".
        foreach (['../../etc/passwd', 'Well Beings/Weta', '.', '..'] as $title) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', ProgrammeArtwork::key($title));
        }
    }

    public function testAnEmptyTitleIsFiledNowhere(): void
    {
        $this->assertSame('', ProgrammeArtwork::key('   '));
        $this->assertNull($this->artwork->pathFor('   '));
    }

    public function testAPictureIsFoundOnceItIsThere(): void
    {
        $key = ProgrammeArtwork::key('Jeopardy!');
        file_put_contents($this->directory . "/artwork/$key.jpg", 'not really a jpeg');

        $this->assertSame($this->directory . "/artwork/$key.jpg", $this->artwork->pathFor('Jeopardy!'));
        $this->assertNull($this->artwork->pathFor('Wheel of Fortune'));
    }

    public function testAMissIsRememberedAndThenForgotten(): void
    {
        $key = ProgrammeArtwork::key('Paid Programming');
        $this->store->rememberArtworkMiss($key, 'Paid Programming');

        $this->assertTrue($this->store->artworkMissedRecently($key, time() - 86400));
        // Old enough to be worth asking again: a programme missing today may be added later.
        $this->assertFalse($this->store->artworkMissedRecently($key, time() + 1));
    }

    public function testATitleNeverLookedUpHasNoMiss(): void
    {
        $this->assertFalse($this->store->artworkMissedRecently(ProgrammeArtwork::key('Jeopardy!'), 0));
    }

    public function testAMissIsRecordedOncePerTitle(): void
    {
        $key = ProgrammeArtwork::key('Paid Programming');
        $this->store->rememberArtworkMiss($key, 'Paid Programming');
        $this->store->rememberArtworkMiss($key, 'Paid Programming');

        $this->assertTrue($this->store->artworkMissedRecently($key, 0));
    }
}
