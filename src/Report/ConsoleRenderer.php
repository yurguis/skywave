<?php

declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave\Report;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Skywave\Ac3\SyncFrame;
use Skywave\Avc\SequenceParameterSet;
use Skywave\Channel;
use Skywave\Descriptor\Ac3AudioStreamDescriptor;
use Skywave\Descriptor\CaptionServiceDescriptor;
use Skywave\Descriptor\ContentAdvisoryDescriptor;
use Skywave\Descriptor\ExtendedChannelNameDescriptor;
use Skywave\Descriptor\ServiceLocationDescriptor;
use Skywave\Event;
use Skywave\Mpeg2\SequenceHeader;
use Skywave\Parser;
use Skywave\Stream;
use Skywave\TableEntry;

/**
 * Renders a parsed transport stream to a human-readable plain-text report.
 *
 * Pure formatter: pulls data from the Parser via getters and returns a string;
 * never echoes. Test it by asserting on its return value.
 */
class ConsoleRenderer
{
    private const HEAVY_RULE = '============================================================================';
    private const LIGHT_RULE = '----------------------------------------------------------------------------';

    /**
     * @throws Exception
     */
    public function render(Parser $parser, ?string $sourceLabel = null): string
    {
        return $this->renderHeader($sourceLabel)
            . $this->renderOverview($parser)
            . $this->renderTransportPrograms($parser)
            . $this->renderMgt($parser)
            . $this->renderTvct($parser)
            . $this->renderEvents($parser);
    }

    private function renderTransportPrograms(Parser $parser): string
    {
        $pat = $parser->getProgramAssociationTable();
        if ($pat === null) {
            return '';
        }

        $out = sprintf(
            "  Transport-level Programs (PAT / PMT)  -  TSID 0x%04X  (PAT v%d)\n",
            $pat->getTransportStreamId(),
            $pat->getVersionNumber()
        );
        $out .= '  ' . self::LIGHT_RULE . "\n";

        $networkPid = $pat->getNetworkPid();
        if ($networkPid !== null) {
            $out .= sprintf("  Network PID: 0x%04X\n", $networkPid);
        }

        foreach ($pat->getPrograms() as $programNumber => $pmtPid) {
            $pmt = $parser->getProgramMapTable($pmtPid);
            if ($pmt === null) {
                $out .= sprintf("  Program %-3d  PMT 0x%04X  (not captured)\n", $programNumber, $pmtPid);

                continue;
            }
            $out .= sprintf(
                "  Program %-3d  PMT 0x%04X v%d   PCR 0x%04X\n",
                $programNumber,
                $pmtPid,
                $pmt->getVersionNumber(),
                $pmt->getPcrPid()
            );

            $programDescriptors = $pmt->getProgramDescriptors();
            if ($programDescriptors !== []) {
                foreach ($programDescriptors as $name => $_descriptor) {
                    $out .= sprintf("    [program] %s\n", $name);
                }
            }

            foreach ($pmt->getStreams() as $stream) {
                $out .= sprintf(
                    "    0x%04X  0x%02X  %s\n",
                    $stream->getPid(),
                    $stream->getType(),
                    $stream->getTypeName()
                );
                $out .= $this->renderStreamDetails($stream, $parser);
            }
            $out .= "\n";
        }

        return $out;
    }

    private function renderStreamDetails(Stream $stream, Parser $parser): string
    {
        $detailIndent = '              ';
        $out          = '';

        $descriptors = $stream->getDescriptors();

        $ac3 = $descriptors[Ac3AudioStreamDescriptor::DESCRIPTOR_NAME] ?? null;
        if ($ac3 instanceof Ac3AudioStreamDescriptor) {
            $out .= $detailIndent . 'PMT descriptor: ' . $ac3->getSummary() . "\n";
        }

        if ($stream->getType() === Stream::TYPE_AC3) {
            $out .= $this->renderAc3Bitstream($parser->getAc3SyncFrame($stream->getPid()), $ac3, $detailIndent);
        }

        if ($stream->getType() === Stream::TYPE_MPEG2_VIDEO) {
            $out .= $this->renderVideoBitstream($parser->getMpeg2SequenceHeader($stream->getPid()), $detailIndent);
        }

        if ($stream->getType() === Stream::TYPE_AVC_VIDEO) {
            $out .= $this->renderVideoBitstream($parser->getAvcSps($stream->getPid()), $detailIndent);
        }

        $cc = $descriptors[CaptionServiceDescriptor::DESCRIPTOR_NAME] ?? null;
        if ($cc instanceof CaptionServiceDescriptor) {
            $summary = $cc->getSummary();
            if ($summary !== '') {
                $out .= $detailIndent . 'Captions: ' . $summary . "\n";
            }
        }

        return $out;
    }

    private function renderAc3Bitstream(?SyncFrame $frame, ?Ac3AudioStreamDescriptor $descriptor, string $indent): string
    {
        if ($frame === null) {
            return $indent . "Bitstream:      (no sync frame sampled)\n";
        }

        $line = $indent . 'Bitstream:      ' . $frame->getSummary();

        if ($descriptor !== null && $this->ac3LayoutMismatch($frame, $descriptor)) {
            $line .= '   [!= PMT descriptor]';
        }

        return $line . "\n";
    }

    /**
     * @param SequenceHeader|SequenceParameterSet|null $header
     */
    private function renderVideoBitstream($header, string $indent): string
    {
        if ($header === null) {
            return $indent . "Bitstream:      (no sequence header sampled)\n";
        }

        return $indent . 'Bitstream:      ' . $header->getSummary() . "\n";
    }

    private function ac3LayoutMismatch(SyncFrame $frame, Ac3AudioStreamDescriptor $descriptor): bool
    {
        // The descriptor's num_channels uses the same acmod codes 0..7 as the bitstream
        // (codes 9..13 are "up to N" upper bounds; we treat any inequality there as a mismatch too).
        $descriptorChannelMode = $descriptor->getChannelMode();
        $bitstreamLayout       = $frame->getAcmodLayout();

        if ($descriptorChannelMode !== $bitstreamLayout && strpos($descriptorChannelMode, $bitstreamLayout) === false) {
            return true;
        }

        // Descriptor cannot express LFE; if the bitstream has LFE on, the descriptor is incomplete.
        return $frame->hasLfe();
    }

    private function renderHeader(?string $sourceLabel): string
    {
        $title = '  MPEG-TS / ATSC PSIP Analyzer';
        if ($sourceLabel !== null && $sourceLabel !== '') {
            $title .= '  -  ' . $sourceLabel;
        }

        return self::HEAVY_RULE . "\n" . $title . "\n" . self::HEAVY_RULE . "\n";
    }

    /**
     * @throws Exception
     */
    private function renderOverview(Parser $parser): string
    {
        $out = '';

        $stt = $parser->getSystemTimeTable();
        if ($stt !== null) {
            $out .= sprintf(
                "  System time:    %s UTC  (GPS-UTC offset: %ds)\n",
                $stt->getUtcDateTime()->format('Y-m-d H:i:s'),
                $stt->getGpsUtcOffset()
            );
            if ($stt->isDaylightSavings()) {
                $out .= sprintf(
                    "  Daylight saving: in effect (day %d, hour %d)\n",
                    $stt->getDsDayOfMonth(),
                    $stt->getDsHour()
                );
            }
        }

        $out .= sprintf("  Packets read:   %d\n", $parser->getCounter());

        if ($parser->isSingleProgramStream()) {
            $out .= "  Stream type:    single-program (no PSIP - tuner has demuxed one program)\n";
        }

        return $out . "\n";
    }

    private function renderMgt(Parser $parser): string
    {
        $mgt = $parser->getMasterGuideTable();
        if ($mgt === null) {
            return '';
        }

        $entries = $mgt->getEntries();
        $out     = sprintf("  Master Guide Table  (%d entries)\n", count($entries));
        $out .= '  ' . self::LIGHT_RULE . "\n";
        $out .= sprintf("  %-22s %-7s %7s %9s\n", 'Type', 'PID', 'Version', 'Bytes');

        foreach ($entries as $entry) {
            $out .= sprintf(
                "  %-22s 0x%04X  %7d %9s\n",
                TableEntry::tableTypeName($entry->getTableType()),
                $entry->getPid(),
                $entry->getVersionNumber(),
                number_format($entry->getNumberBytes())
            );
        }

        return $out . "\n";
    }

    private function renderTvct(Parser $parser): string
    {
        $tvct = $parser->getVirtualChannelTable();
        if ($tvct === null) {
            if ($parser->isSingleProgramStream()) {
                return '';
            }

            return "  No Virtual Channel Table captured.\n\n";
        }

        $tsid = $tvct->getTransportStreamId();
        $out  = sprintf(
            "  Transport Stream  0x%04X (%d)  -  %d channels  (TVCT v%d)\n",
            $tsid,
            $tsid,
            $tvct->getNumberOfChannels(),
            $tvct->getVersionNumber()
        );
        $out .= '  ' . self::LIGHT_RULE . "\n";
        $out .= sprintf("  %-5s %-12s %-24s %-8s %s\n", 'Ch', 'Name', 'Service', 'Source', 'Modulation');

        foreach ($tvct->getChannels() as $channel) {
            $out .= sprintf(
                "  %-5s %-12s %-24s 0x%04X   %s\n",
                $channel->getChannel(),
                $this->truncate($channel->getShortName(), 12),
                $this->truncate($channel->getServiceType(), 24),
                $channel->getSourceId(),
                $channel->getModulationMode()
            );
            $out .= $this->renderChannelDetails($channel, $parser);
            $out .= "\n";
        }

        return $out;
    }

    private function renderChannelDetails(Channel $channel, Parser $parser): string
    {
        $descriptors = $channel->getDescriptors();
        $out         = '';

        $ecn = $descriptors[ExtendedChannelNameDescriptor::DESCRIPTOR_NAME] ?? null;
        if ($ecn instanceof ExtendedChannelNameDescriptor) {
            $out .= sprintf("           Long name:  %s\n", $ecn->getLongChannelNameText());
        }

        $sld = $descriptors[ServiceLocationDescriptor::DESCRIPTOR_NAME] ?? null;
        if ($sld instanceof ServiceLocationDescriptor) {
            $out .= sprintf("           PCR PID:    0x%04X\n", $sld->getPcrPid());

            $streams = $sld->getStreams();
            if ($streams !== []) {
                $out .= '           Streams:';
                $first = true;
                foreach ($streams as $stream) {
                    $prefix = $first ? '    ' : '                       ';
                    $first  = false;
                    $line   = sprintf('0x%04X  %s', $stream->getPid(), $stream->getTypeName());
                    if ($stream->getType() === Stream::TYPE_AC3 && $stream->getLang() !== null && $stream->getLang() !== '') {
                        $line .= sprintf('  (%s)', $stream->getLang());
                    }
                    $out .= $prefix . $line . "\n";
                }
            }
        }

        $channelDescription = $parser->getChannelDescription($channel->getSourceId());
        if ($channelDescription !== null && $channelDescription !== '') {
            $indent = str_repeat(' ', 11);
            $out .= $indent . "About:\n";
            $wrapped = wordwrap($channelDescription, 70, "\n" . $indent . '  ', true);
            $out .= $indent . '  ' . $wrapped . "\n";
        }

        return $out;
    }

    /**
     * @throws Exception
     */
    private function renderEvents(Parser $parser): string
    {
        $tvct = $parser->getVirtualChannelTable();
        if ($tvct === null) {
            return '';
        }

        if (!$this->hasAnyEvents($parser, $tvct->getChannels())) {
            return '';
        }

        $stt    = $parser->getSystemTimeTable();
        $offset = $stt !== null ? $stt->getGpsUtcOffset() : 0;

        $out = "  Event Information\n";
        $out .= '  ' . self::LIGHT_RULE . "\n";

        foreach ($tvct->getChannels() as $channel) {
            $sourceId = $channel->getSourceId();
            $events   = $parser->getEventsForSource($sourceId);
            if ($events === []) {
                continue;
            }

            $out .= sprintf(
                "\n  %s  %s  (%d events)\n",
                $channel->getChannel(),
                $channel->getShortName(),
                count($events)
            );

            foreach ($events as $event) {
                $out .= $this->renderEventLine($event, $offset);
                $description = $parser->getEventDescription($sourceId, $event->getEventId());
                if ($description !== null && $description !== '') {
                    $out .= $this->renderDescription($description);
                }
            }
        }

        return $out;
    }

    private function renderDescription(string $text): string
    {
        // Aligns under the title column: 4 (indent) + 16 (date) + 2 + 7 (duration field) + 2 = 31.
        $indent  = str_repeat(' ', 31);
        $wrapped = wordwrap($text, 68, "\n" . $indent, true);

        return $indent . $wrapped . "\n";
    }

    /**
     * @param Channel[] $channels
     */
    private function hasAnyEvents(Parser $parser, array $channels): bool
    {
        foreach ($channels as $channel) {
            if ($parser->getEventsForSource($channel->getSourceId()) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @throws Exception
     */
    private function renderEventLine(Event $event, int $gpsUtcOffset): string
    {
        $utc = (new DateTimeImmutable('@' . $event->getUtcTimestamp($gpsUtcOffset)))
            ->setTimezone(new DateTimeZone('UTC'));

        $title  = $event->getTitle();
        $rating = $this->extractUsRating($event);
        if ($rating !== null) {
            $title .= '  [' . $rating . ']';
        }

        return sprintf(
            "    %s  %-7s  %s\n",
            $utc->format('Y-m-d H:i'),
            '(' . self::formatDuration($event->getLengthSeconds()) . ')',
            $title
        );
    }

    private function extractUsRating(Event $event): ?string
    {
        $cad = $event->getDescriptors()[ContentAdvisoryDescriptor::DESCRIPTOR_NAME] ?? null;
        if (!$cad instanceof ContentAdvisoryDescriptor) {
            return null;
        }

        return $cad->getUsRatingLabel();
    }

    private static function formatDuration(int $seconds): string
    {
        $totalMin = intdiv($seconds, 60);
        if ($totalMin < 60) {
            return $totalMin . 'm';
        }
        $h = intdiv($totalMin, 60);
        $m = $totalMin % 60;

        return $m === 0 ? sprintf('%dh', $h) : sprintf('%dh%dm', $h, $m);
    }

    private function truncate(string $s, int $width): string
    {
        if (mb_strlen($s) <= $width) {
            return $s;
        }

        return mb_substr($s, 0, $width - 1) . '~';
    }
}
