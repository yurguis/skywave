<?php

namespace Skywave\Tests\Hdhomerun;

use PHPUnit\Framework\TestCase;
use Skywave\Hdhomerun\Exception\ProtocolException;
use Skywave\Hdhomerun\Packet;

/**
 * The wire format spoken to the tuner. Everything else assumes these bytes are right, so
 * they are worth pinning down without a device in the room.
 */
class PacketTest extends TestCase
{
    public function testAFrameSurvivesEncodingAndDecoding(): void
    {
        $frame = Packet::encodeFrame(Packet::TYPE_GETSET_REQ, 'hello');

        $decoded = Packet::decodeFrame($frame);

        $this->assertNotNull($decoded);
        $this->assertSame(Packet::TYPE_GETSET_REQ, $decoded['type']);
        $this->assertSame('hello', $decoded['payload']);
        $this->assertSame(strlen($frame), $decoded['size']);
    }

    public function testDecodingWaitsForTheRestOfAFrame(): void
    {
        $frame = Packet::encodeFrame(Packet::TYPE_DISCOVER_REQ, 'payload');

        // Nothing at all, a header without its body, and a body without its CRC.
        $this->assertNull(Packet::decodeFrame(substr($frame, 0, 3)));
        $this->assertNull(Packet::decodeFrame(substr($frame, 0, 6)));
        $this->assertNull(Packet::decodeFrame(substr($frame, 0, -1)));
    }

    public function testMoreThanOneFrameCanShareABuffer(): void
    {
        $first  = Packet::encodeFrame(Packet::TYPE_DISCOVER_RPY, 'one');
        $second = Packet::encodeFrame(Packet::TYPE_GETSET_RPY, 'two');

        $decoded = Packet::decodeFrame($first . $second);

        $this->assertNotNull($decoded);
        $this->assertSame('one', $decoded['payload']);

        $rest = Packet::decodeFrame(substr($first . $second, $decoded['size']));

        $this->assertNotNull($rest);
        $this->assertSame('two', $rest['payload']);
    }

    public function testACorruptFrameIsRefused(): void
    {
        $frame = Packet::encodeFrame(Packet::TYPE_GETSET_REQ, 'hello');

        // Flip a byte of the payload, leaving the CRC describing what was sent before.
        $frame[5] = 'H';

        $this->expectException(ProtocolException::class);
        Packet::decodeFrame($frame);
    }

    public function testShortValuesUseOneLengthByte(): void
    {
        $tlv = Packet::encodeTlv(Packet::TAG_GETSET_NAME, '/tuner0/status');

        $this->assertSame(2 + strlen('/tuner0/status'), strlen($tlv));
        $this->assertSame([[Packet::TAG_GETSET_NAME, '/tuner0/status']], Packet::decodeTlvs($tlv));
    }

    public function testLongValuesUseTwoLengthBytes(): void
    {
        // Anything over 127 bytes carries its length in two bytes; a status string or a
        // channel map easily passes that.
        $value = str_repeat('a', 200);
        $tlv   = Packet::encodeTlv(Packet::TAG_GETSET_VALUE, $value);

        $this->assertSame(3 + 200, strlen($tlv));
        $this->assertSame([[Packet::TAG_GETSET_VALUE, $value]], Packet::decodeTlvs($tlv));
    }

    public function testTlvsAreReturnedInOrderAndMayRepeat(): void
    {
        $payload = Packet::encodeTlv(Packet::TAG_GETSET_NAME, 'first')
            . Packet::encodeTlv(Packet::TAG_GETSET_VALUE, 'second')
            . Packet::encodeTlv(Packet::TAG_GETSET_NAME, 'third');

        $this->assertSame([
            [Packet::TAG_GETSET_NAME, 'first'],
            [Packet::TAG_GETSET_VALUE, 'second'],
            [Packet::TAG_GETSET_NAME, 'third'],
        ], Packet::decodeTlvs($payload));
    }

    public function testATruncatedTlvEndsDecodingQuietly(): void
    {
        $payload = Packet::encodeTlv(Packet::TAG_GETSET_NAME, 'kept')
            . substr(Packet::encodeTlv(Packet::TAG_GETSET_VALUE, 'cut short'), 0, 4);

        $this->assertSame([[Packet::TAG_GETSET_NAME, 'kept']], Packet::decodeTlvs($payload));
    }

    public function testCStringStopsAtTheTerminator(): void
    {
        $this->assertSame('/tuner0/status', Packet::cString("/tuner0/status\0"));
        $this->assertSame('plain', Packet::cString('plain'));
        $this->assertSame('', Packet::cString("\0trailing rubbish"));
    }
}
