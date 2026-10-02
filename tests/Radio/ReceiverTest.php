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
}
