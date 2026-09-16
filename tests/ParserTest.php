<?php

namespace Skywave\Tests;

use PHPUnit\Framework\TestCase;
use Skywave\Parser;

/**
 * The transport stream parser reads whatever the air gives it, including rubbish, so its
 * refusals matter as much as what it accepts.
 */
class ParserTest extends TestCase
{
    public function testAPacketWithoutTheSyncByteIsIgnored(): void
    {
        $parser = new Parser();

        $packet = "\x00" . str_repeat("\xFF", 187);

        $this->assertSame(Parser::RETURN_TYPE_CONTINUE, $parser->analyze($packet));
        $this->assertSame(0, $parser->getCounter());
    }

    public function testAPacketFlaggedCorruptIsIgnored(): void
    {
        // transport_error_indicator set: the tuner already knows these bytes are damaged.
        $parser = new Parser();

        $packet = "\x47\x80\x00\x10" . str_repeat("\xFF", 184);

        $this->assertSame(Parser::RETURN_TYPE_CONTINUE, $parser->analyze($packet));
        $this->assertSame(0, $parser->getCounter());
    }

    public function testPacketsOnUninterestingPidsAreSkipped(): void
    {
        // 0x1FFF is the null packet a mux uses to pad; it carries nothing to read.
        $parser = new Parser();

        $packet = "\x47\x1F\xFF\x10" . str_repeat("\xFF", 184);

        $this->assertSame(Parser::RETURN_TYPE_CONTINUE, $parser->analyze($packet));
        $this->assertSame(0, $parser->getCounter());
        $this->assertNull($parser->getProgramAssociationTable());
    }

    public function testNothingIsReportedBeforeAnythingIsRead(): void
    {
        $parser = new Parser();

        $this->assertNull($parser->getProgramAssociationTable());
        $this->assertNull($parser->getVirtualChannelTable());
        $this->assertNull($parser->getMasterGuideTable());
        $this->assertSame([], $parser->getEventsForSource(1));
    }
}
