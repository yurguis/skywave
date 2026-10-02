<?php

namespace Skywave\Tests\Radio;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Skywave\Radio\RtlTcp;

/**
 * The bytes exchanged with a dongle shared over a network. nrsc5 reads the same ones, so a
 * simulator that gets them wrong is not a quieter station, it is no dongle at all.
 */
class RtlTcpTest extends TestCase
{
    public function testAConnectionOpensWithTheTunerAndItsGainSteps(): void
    {
        $header = RtlTcp::encodeHeader(RtlTcp::TUNER_R820T, 29);

        $this->assertSame("RTL0\x00\x00\x00\x05\x00\x00\x00\x1D", $header);
        $this->assertSame(['tunerType' => 5, 'gainCount' => 29], RtlTcp::decodeHeader($header));
        $this->assertSame('R820T', RtlTcp::tunerName(5));
    }

    public function testSomethingThatIsNotADongleIsNotTakenForOne(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('RTL0');

        RtlTcp::decodeHeader("HTTP/1.1 400\r\n");
    }

    public function testACommandIsOneByteAndABigEndianValue(): void
    {
        // 90.5 MHz, as nrsc5 asks for it.
        $this->assertSame("\x01\x05\x64\xEB\xA0", RtlTcp::encodeCommand(RtlTcp::SET_FREQUENCY, 90500000));
    }

    public function testCommandsAreTakenWholeAndTheRestIsLeftForLater(): void
    {
        $buffer = RtlTcp::encodeCommand(RtlTcp::SET_SAMPLE_RATE, 1488375)
            . RtlTcp::encodeCommand(RtlTcp::SET_FREQUENCY, 90500000)
            . "\x04\x00";

        $commands = RtlTcp::decodeCommands($buffer);

        $this->assertSame([
            ['command' => RtlTcp::SET_SAMPLE_RATE, 'value' => 1488375],
            ['command' => RtlTcp::SET_FREQUENCY, 'value' => 90500000],
        ], $commands);
        $this->assertSame("\x04\x00", $buffer);
    }

    public function testAnAddressIsReadAsNrsc5ReadsIt(): void
    {
        $this->assertSame(['host' => '192.168.1.20', 'port' => 1234], RtlTcp::parseAddress('192.168.1.20'));
        $this->assertSame(['host' => 'pi.local', 'port' => 5555], RtlTcp::parseAddress(' pi.local:5555 '));
    }

    public function testAnAddressThatIsNotOneIsRefused(): void
    {
        $this->expectException(RuntimeException::class);

        RtlTcp::parseAddress('host:port:again');
    }
}
