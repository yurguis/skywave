<?php

namespace Skywave\Tests\Dvr;

use PHPUnit\Framework\TestCase;
use Skywave\Dvr\TunerReservations;

/**
 * Reservations are how a recording keeps live playback and guide updates off its tuner.
 * They are shared between containers through one file, so the rules matter.
 */
class TunerReservationsTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/skywave-reservations-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->directory);
    }

    public function testAReservedTunerIsReservedAndSaysWhy(): void
    {
        $reservations = new TunerReservations($this->directory);

        $token = $reservations->reserve('192.168.1.62', 0, 'Recording Jeopardy!');

        $this->assertNotNull($token);
        $this->assertTrue($reservations->isReserved('192.168.1.62', 0));

        $held = $reservations->find('192.168.1.62', 0);

        $this->assertNotNull($held);
        $this->assertSame('Recording Jeopardy!', $held['label']);
    }

    public function testOtherTunersAreUnaffected(): void
    {
        $reservations = new TunerReservations($this->directory);
        $reservations->reserve('192.168.1.62', 0, 'Recording');

        $this->assertFalse($reservations->isReserved('192.168.1.62', 1));
        $this->assertFalse($reservations->isReserved('192.168.1.99', 0));
        $this->assertNull($reservations->find('192.168.1.62', 1));
    }

    public function testATunerCannotBeReservedTwice(): void
    {
        $reservations = new TunerReservations($this->directory);
        $reservations->reserve('192.168.1.62', 0, 'Recording the news');

        $this->assertNull($reservations->reserve('192.168.1.62', 0, 'Recording something else'));
    }

    public function testReleasingFreesTheTuner(): void
    {
        $reservations = new TunerReservations($this->directory);
        $token        = $reservations->reserve('192.168.1.62', 0, 'Recording');

        $reservations->release((string) $token);

        $this->assertFalse($reservations->isReserved('192.168.1.62', 0));
        $this->assertNotNull($reservations->reserve('192.168.1.62', 0, 'Recording something else'));
    }

    public function testATunerCanBeFreedWithoutItsToken(): void
    {
        // The page knows the device and tuner, never the token the recorder was given.
        $reservations = new TunerReservations($this->directory);
        $reservations->reserve('192.168.1.62', 2, 'Recording');

        $reservations->releaseTuner('192.168.1.62', 2);

        $this->assertFalse($reservations->isReserved('192.168.1.62', 2));
    }

    public function testReservationsAreSharedThroughTheDirectory(): void
    {
        // The web container and the recorder are different processes: what one reserves,
        // the other has to see.
        $recorder = new TunerReservations($this->directory);
        $website  = new TunerReservations($this->directory);

        $recorder->reserve('192.168.1.62', 1, 'Recording the game');

        $this->assertTrue($website->isReserved('192.168.1.62', 1));
        $this->assertCount(1, $website->all());
    }
}
