<?php

namespace Skywave\Tests\Hdhomerun;

use PHPUnit\Framework\TestCase;
use Skywave\Hdhomerun\TunerStatus;

/**
 * Reading a tuner's status line, and telling a healthy lock from a readable stream.
 *
 * The two are not the same thing. A tuner locked to ATSC 3.0 reports a perfectly good
 * signal and carries no transport stream at all, so asking it for one gets a 503 from the
 * device. Every status line here was taken from a real tuner rather than written by hand.
 */
class TunerStatusTest extends TestCase
{
    /** An ATSC 3.0 lock: healthy signal, and bps=0 because there is no transport stream. */
    private const ATSC3 = 'ch=auto:31 lock=atsc3 ss=62 snq=47 seq=0 bps=0 pps=0';

    /** An ordinary 8VSB lock on the same device, carrying a multiplex. */
    private const VSB = 'ch=auto:32 lock=8vsb ss=61 snq=87 seq=100 bps=19636224 pps=0';

    public function testAnOrdinaryBroadcastLockCarriesATransportStream(): void
    {
        $status = new TunerStatus(self::VSB);

        $this->assertTrue($status->isLockSupported());
        $this->assertTrue($status->carriesTransportStream());
    }

    public function testAnAtsc3LockCarriesNoTransportStream(): void
    {
        $status = new TunerStatus(self::ATSC3);

        // The signal is fine. Saying otherwise would be a different untruth.
        $this->assertTrue($status->isLockSupported());
        $this->assertFalse($status->carriesTransportStream());
    }

    public function testAnAtsc3LockStillReportsItsSignal(): void
    {
        $status = new TunerStatus(self::ATSC3);

        $this->assertSame('atsc3', $status->getLock());
        $this->assertSame(62, $status->getSignalStrength());
        $this->assertSame('auto:31', $status->getChannel());
        // No transport stream behind the lock, which is the whole point.
        $this->assertSame(0, $status->getBitsPerSecond());
    }

    public function testNothingLockedCarriesNothing(): void
    {
        $status = new TunerStatus('ch=none lock=none ss=0 snq=0 seq=0 bps=0 pps=0');

        $this->assertFalse($status->isLockSupported());
        $this->assertFalse($status->carriesTransportStream());
    }

    public function testASignalTheTunerCannotDemodulateCarriesNothing(): void
    {
        // Parenthesised locks are signals the tuner sees but cannot demodulate.
        $status = new TunerStatus('ch=auto:12 lock=(ntsc) ss=70 snq=0 seq=0 bps=0 pps=0');

        $this->assertTrue($status->isLockUnsupported());
        $this->assertFalse($status->carriesTransportStream());
    }
}
