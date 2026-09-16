<?php declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave\Report;

use DateTimeImmutable;
use DateTimeZone;
use Skywave\Channel;
use Skywave\Descriptor\Ac3AudioStreamDescriptor;
use Skywave\Descriptor\CaptionServiceDescriptor;
use Skywave\Descriptor\ContentAdvisoryDescriptor;
use Skywave\Descriptor\ExtendedChannelNameDescriptor;
use Skywave\Descriptor\ServiceLocationDescriptor;
use Skywave\Event;
use Skywave\Parser;
use Skywave\Stream;
use Skywave\TableEntry;

/**
 * Renders a parsed transport stream as a JSON-ready array, with the same content
 * as ConsoleRenderer. Times are ISO 8601 UTC strings; PIDs are plain integers.
 */
class JsonRenderer
{
    /**
     * @return array<string, mixed>
     */
    public function render(Parser $parser): array
    {
        $stt = $parser->getSystemTimeTable();
        $pat = $parser->getProgramAssociationTable();

        return [
            'systemTime'        => $stt === null ? null : [
                'utc'            => $stt->getUtcDateTime()->format(DATE_ATOM),
                'gpsUtcOffset'   => $stt->getGpsUtcOffset(),
                'daylightSaving' => $stt->isDaylightSavings(),
            ],
            'packetsRead'       => $parser->getCounter(),
            'singleProgram'     => $parser->isSingleProgramStream(),
            'transportStreamId' => $pat === null ? null : $pat->getTransportStreamId(),
            'programs'          => $this->renderPrograms($parser),
            'guideTables'       => $this->renderGuideTables($parser),
            'channels'          => $this->renderChannels($parser, $stt === null ? 0 : $stt->getGpsUtcOffset()),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function renderPrograms(Parser $parser): array
    {
        $pat = $parser->getProgramAssociationTable();

        if ($pat === null) {
            return [];
        }

        $programs = [];

        foreach ($pat->getPrograms() as $programNumber => $pmtPid) {
            $pmt = $parser->getProgramMapTable($pmtPid);

            $programs[] = [
                'number'   => $programNumber,
                'pmtPid'   => $pmtPid,
                'captured' => $pmt !== null,
                'pcrPid'   => $pmt === null ? null : $pmt->getPcrPid(),
                'streams'  => $pmt === null ? [] : array_map(fn(Stream $stream) => $this->renderStream($stream, $parser), $pmt->getStreams()),
            ];
        }

        return $programs;
    }

    /**
     * @return array<string, mixed>
     */
    private function renderStream(Stream $stream, Parser $parser): array
    {
        $descriptors = $stream->getDescriptors();
        $ac3         = $descriptors[Ac3AudioStreamDescriptor::DESCRIPTOR_NAME] ?? null;
        $captions    = $descriptors[CaptionServiceDescriptor::DESCRIPTOR_NAME] ?? null;

        switch ($stream->getType()) {
            case Stream::TYPE_AC3:
                $bitstream = $parser->getAc3SyncFrame($stream->getPid());
                break;
            case Stream::TYPE_MPEG2_VIDEO:
                $bitstream = $parser->getMpeg2SequenceHeader($stream->getPid());
                break;
            case Stream::TYPE_AVC_VIDEO:
                $bitstream = $parser->getAvcSps($stream->getPid());
                break;
            default:
                $bitstream = null;
        }

        return [
            'pid'        => $stream->getPid(),
            'type'       => $stream->getType(),
            'typeName'   => $stream->getTypeName(),
            'language'   => self::language($stream),
            'descriptor' => $ac3 instanceof Ac3AudioStreamDescriptor ? $ac3->getSummary() : null,
            'bitstream'  => $bitstream === null ? null : $bitstream->getSummary(),
            'captions'   => $captions instanceof CaptionServiceDescriptor && $captions->getSummary() !== '' ? $captions->getSummary() : null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function renderGuideTables(Parser $parser): array
    {
        $mgt = $parser->getMasterGuideTable();

        if ($mgt === null) {
            return [];
        }

        return array_map(fn(TableEntry $entry) => [
            'type'    => TableEntry::tableTypeName($entry->getTableType()),
            'pid'     => $entry->getPid(),
            'version' => $entry->getVersionNumber(),
            'bytes'   => $entry->getNumberBytes(),
        ], array_values($mgt->getEntries()));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function renderChannels(Parser $parser, int $gpsUtcOffset): array
    {
        $vct = $parser->getVirtualChannelTable();

        if ($vct === null) {
            return [];
        }

        $channels = [];

        foreach ($vct->getChannels() as $channel) {
            $channels[] = $this->renderChannel($channel, $parser, $gpsUtcOffset);
        }

        return $channels;
    }

    /**
     * @return array<string, mixed>
     */
    private function renderChannel(Channel $channel, Parser $parser, int $gpsUtcOffset): array
    {
        $descriptors = $channel->getDescriptors();
        $longName    = $descriptors[ExtendedChannelNameDescriptor::DESCRIPTOR_NAME] ?? null;
        $location    = $descriptors[ServiceLocationDescriptor::DESCRIPTOR_NAME] ?? null;
        $sourceId    = $channel->getSourceId();

        return [
            'channel'       => $channel->getChannel(),
            'name'          => $channel->getShortName(),
            'longName'      => $longName instanceof ExtendedChannelNameDescriptor ? $longName->getLongChannelNameText() : null,
            'serviceType'   => $channel->getServiceType(),
            'modulation'    => $channel->getModulationMode(),
            'programNumber' => $channel->getProgramNumber(),
            'sourceId'      => $sourceId,
            'hidden'        => $channel->isHidden(),
            'hideGuide'     => $channel->isHideGuide(),
            'pcrPid'        => $location instanceof ServiceLocationDescriptor ? $location->getPcrPid() : null,
            'streams'       => $location instanceof ServiceLocationDescriptor ? array_map(fn(Stream $stream) => [
                'pid'      => $stream->getPid(),
                'typeName' => $stream->getTypeName(),
                'language' => self::language($stream),
            ], array_values($location->getStreams())) : [],
            'description'   => $parser->getChannelDescription($sourceId),
            'events'        => array_map(
                fn(Event $event) => $this->renderEvent($event, $parser->getEventDescription($sourceId, $event->getEventId()), $gpsUtcOffset),
                array_values($parser->getEventsForSource($sourceId))
            ),
        ];
    }

    /**
     * ISO 639 code, or null when the field is unset; video streams often carry
     * zero bytes there.
     */
    private static function language(Stream $stream): ?string
    {
        $language = $stream->getLang();

        return $language !== null && preg_match('/^[a-z]{3}$/i', $language) ? $language : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function renderEvent(Event $event, ?string $description, int $gpsUtcOffset): array
    {
        $start    = $event->getUtcTimestamp($gpsUtcOffset);
        $advisory = $event->getDescriptors()[ContentAdvisoryDescriptor::DESCRIPTOR_NAME] ?? null;

        return [
            'eventId'         => $event->getEventId(),
            'start'           => (new DateTimeImmutable("@$start"))->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM),
            'durationSeconds' => $event->getLengthSeconds(),
            'title'           => $event->getTitle(),
            'rating'          => $advisory instanceof ContentAdvisoryDescriptor ? $advisory->getUsRatingLabel() : null,
            'description'     => $description === '' ? null : $description,
        ];
    }
}
