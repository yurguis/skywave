<?php

namespace Skywave\Tests\Radio;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Skywave\Radio\Receiver;

/**
 * The nrsc5 command a station is played with, checked as text: what it is told is the whole
 * of what this project decides about receiving a station.
 */
class ReceiverTest extends TestCase
{
    public function testRadioIsOffUntilItIsToldWhereItsDongleIs(): void
    {
        $this->assertFalse((new Receiver(PHP_BINARY))->isConfigured());
        $this->assertTrue((new Receiver(PHP_BINARY, '127.0.0.1'))->isConfigured());
        $this->assertTrue((new Receiver(PHP_BINARY, null, 0))->isConfigured());
    }

    public function testADongleSharedOverTheNetworkIsNamedToNrsc5(): void
    {
        // Any executable stands in for nrsc5: only its path ends up in the command.
        $receiver = new Receiver(PHP_BINARY, '192.168.1.20');

        $this->assertSame(
            [PHP_BINARY, '-H', '192.168.1.20:1234', '-o', '-', '-t', 'raw', '--dump-aas-files', '/tmp/session', '90.5', '1'],
            $receiver->arguments(90.5, 1, '/tmp/session')
        );
        $this->assertSame('rtl_tcp at 192.168.1.20:1234', $receiver->describe()['label']);
    }

    public function testADonglePluggedInHereIsChosenByNumberWithItsCorrections(): void
    {
        $receiver = new Receiver(PHP_BINARY, null, 1, 38.6, -2);

        $this->assertSame(
            [PHP_BINARY, '-d', '1', '-g', '38.6', '-p', '-2', '-o', '-', '-t', 'raw', '--dump-aas-files', '/tmp/session', '101.1', '0'],
            $receiver->arguments(101.1, 0, '/tmp/session')
        );
        $this->assertSame('USB dongle 1', $receiver->describe()['label']);
    }

    public function testWithoutNrsc5ThereIsNothingToRunAndItSaysSo(): void
    {
        $receiver = new Receiver('/nowhere/nrsc5', '127.0.0.1');

        $this->assertNull($receiver->binary());
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('nrsc5 is not installed');

        $receiver->arguments(90.5, 0, '/tmp/session');
    }

    public function testAFrequencyIsKeptToTheTenth(): void
    {
        $this->assertSame(90.5, Receiver::validateFrequency(90.50000001));
        $this->assertSame(88.0, Receiver::validateFrequency(88));
    }

    public function testAFrequencyOffTheDialIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('between 87.5 and 108.0');

        Receiver::validateFrequency(162.55);
    }

    public function testAFrequencyThatIsNotANumberIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Receiver::validateFrequency('90.5; rm -rf /');
    }

    public function testAProgramIsOneOfTheEight(): void
    {
        $this->assertSame(7, Receiver::validateProgram(7));

        $this->expectException(InvalidArgumentException::class);

        Receiver::validateProgram(8);
    }

    public function testABadAddressIsCaughtBeforeAnythingIsStarted(): void
    {
        $this->expectException(RuntimeException::class);

        new Receiver(PHP_BINARY, 'not an address');
    }

    public function testAnalogIsOffUntilRtlanalogIsNamed(): void
    {
        $without = new Receiver(PHP_BINARY, '127.0.0.1');

        $this->assertFalse($without->supportsAnalog());
        $this->assertStringContainsString('RTLANALOG', $without->whyAnalogMissing());

        $with = new Receiver(PHP_BINARY, '127.0.0.1', null, null, null, PHP_BINARY);

        $this->assertTrue($with->supportsAnalog());
    }

    public function testTheAnalogCommandSaysWhichModeAndTunesInHertz(): void
    {
        $receiver = new Receiver(PHP_BINARY, '192.168.1.20:1234', null, 40.2, 3, PHP_BINARY);

        // FM asks for the multiplex, not audio: redsea needs the subcarrier that
        // demodulating to sound would throw away.
        $this->assertSame(
            [PHP_BINARY, '-H', '192.168.1.20:1234', '-g', '40.2', '-p', '3', '-M', 'mpx', '-f', '93100000'],
            $receiver->analogArguments('fm', 93.1)
        );

        $this->assertSame(
            [PHP_BINARY, '-H', '192.168.1.20:1234', '-g', '40.2', '-p', '3', '-M', 'am', '-f', '1140000'],
            $receiver->analogArguments('am', 1.14)
        );
    }

    public function testHdRadioIsNotRtlanalogsToTune(): void
    {
        $receiver = new Receiver(PHP_BINARY, '127.0.0.1', null, null, null, PHP_BINARY);

        $this->expectException(InvalidArgumentException::class);
        $receiver->analogArguments('hd', 93.1);
    }

    public function testAnAmFrequencyKeepsItsKilohertz(): void
    {
        // The tenth-of-a-MHz rounding the FM band wants would make 1140 kHz into 1100 and
        // tune the wrong station without saying anything.
        $this->assertSame(1.14, Receiver::validateFrequency(1.14, 'am'));
        $this->assertSame(0.61, Receiver::validateFrequency(0.61, 'am'));
        $this->assertSame(1.7, Receiver::validateFrequency(1.7, 'am'));
    }

    public function testEachBandRefusesTheOthersFrequencies(): void
    {
        foreach ([['am', 93.1], ['fm', 1.14], ['hd', 1.14], ['am', 0.4], ['am', 1.8]] as [$mode, $frequency]) {
            try {
                Receiver::validateFrequency($frequency, $mode);
                $this->fail("$mode should not accept $frequency");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($mode === 'am' ? 'AM stations' : 'FM stations', $e->getMessage());
            }
        }
    }

    public function testAnAmErrorTalksInKilohertz(): void
    {
        // Nobody tuning AM thinks in MHz, so the complaint should not either.
        try {
            Receiver::validateFrequency(2.5, 'am');
            $this->fail('expected a refusal');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('AM stations are between 530 and 1700 kHz, not 2500', $e->getMessage());
        }
    }

    public function testOnlyTheThreeModesExist(): void
    {
        $this->assertSame('fm', Receiver::bandOf('fm'));
        $this->assertSame('fm', Receiver::bandOf('hd'));
        $this->assertSame('am', Receiver::bandOf('am'));

        $this->expectException(InvalidArgumentException::class);
        Receiver::validateMode('shortwave');
    }
}
