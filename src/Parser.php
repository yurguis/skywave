<?php declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave;

use Skywave\Ac3\SyncFrame;
use Skywave\Ac3\SyncFrameSampler;
use Skywave\Avc\SequenceParameterSet;
use Skywave\Avc\SequenceParameterSetSampler;
use Skywave\Io\BinaryReader;
use Skywave\Mpeg2\SequenceHeader;
use Skywave\Mpeg2\SequenceHeaderSampler;

class Parser
{
    public const SYNC_BYTE = 0x47;

    public const RETURN_TYPE_DONE     = 0;
    public const RETURN_TYPE_CONTINUE = 1;

    public const PID_PAT  = 0x0000; // Program Association Table
    public const PID_PSIP = 0x1FFB;

    public const TABLE_ID_PAT  = 0x00; // Program Association Table
    public const TABLE_ID_PMT  = 0x02; // Program Map Table
    public const TABLE_ID_MGT  = 0xC7; // Master Guide Table
    public const TABLE_ID_TVCT = 0xC8; // Terrestrial Virtual Channel Table
    public const TABLE_ID_CVCT = 0xC9; // Cable Virtual Channel Table
    public const TABLE_ID_RRT  = 0xCA; // Rating Region Table
    public const TABLE_ID_EIT  = 0xCB; // Event Information Table per MGT
    public const TABLE_ID_ETT  = 0xCC; // Extended Text Table per MGT
    public const TABLE_ID_STT  = 0xCD; // System Time Table

    /** MGT table_type ranges for EIT-N (0..127) and Event ETT-N (0..127). */
    private const MGT_TABLE_TYPE_CHANNEL_ETT = 0x0004;
    private const MGT_TABLE_TYPE_EIT_FIRST   = 0x0100;
    private const MGT_TABLE_TYPE_EIT_LAST    = 0x017F;
    private const MGT_TABLE_TYPE_ETT_FIRST   = 0x0200;
    private const MGT_TABLE_TYPE_ETT_LAST    = 0x027F;

    /**
     * Raw-packet threshold for concluding a stream carries no PSIP. ATSC mandates
     * MGT/VCT/STT repetition every <=150ms; even at a modest 5 Mbps that's ~830
     * packets, so 3000 is generous without keeping the user waiting on a stream
     * HDHomeRun has already demuxed down to one program.
     */
    private const SINGLE_PROGRAM_THRESHOLD_PACKETS = 3000;

    /**
     * Hard cap on how long we'll wait for elementary-stream samples (sequence
     * headers, SPS, AC-3 sync frames) before declaring "good enough" - avoids
     * hanging forever if one PID never delivers what we're scanning for.
     * 20000 raw packets is ~2s of full multiplex or ~10s of a demuxed program.
     */
    private const ELEMENTARY_SAMPLE_GIVE_UP_PACKETS = 20000;

    public const MODULATION_MODES = [
        4 => "ATSC (8 VSB)",
    ];

    public const SERVICE_TYPES = [
        2 => "ATSC Digital Television",
        3 => "ATSC Audio",
    ];

    protected int $counter = 0;

    protected ?MasterGuideTable $masterGuideTable = null;

    protected ?VirtualChannelTable $virtualChannelTable = null;

    protected ?SystemTimeTable $systemTimeTable = null;

    protected ?ProgramAssociationTable $programAssociationTable = null;

    /** @var array<int, ProgramMapTable> PMT keyed by PMT PID */
    protected array $programMapTables = [];

    /** @var array<int, SectionTable> sections being accumulated, keyed by PID */
    private array $inProgress = [];

    /** @var int[] PIDs we'll accept EIT packets from (learned from MGT) */
    private array $eitPids = [];

    /** @var int[] PIDs we'll accept ETT packets from (learned from MGT) */
    private array $ettPids = [];

    /** @var int[] PMT PIDs learned from PAT */
    private array $pmtPids = [];

    /** @var array<int, array<int, array<int, EventInformationTable>>> [eitPid][sourceId][sectionNumber] => EIT */
    private array $eitSections = [];

    /** @var array<int, array<int, ExtendedTextTable>> [ettPid][sectionNumber] => ETT */
    private array $ettSections = [];

    /** @var array<int, array<int, string>> [sourceId][eventId] => description text */
    private array $eventDescriptions = [];

    /** @var array<int, string> [sourceId] => channel description text (Channel ETT) */
    private array $channelDescriptions = [];

    /** @var int[] PIDs carrying AC-3 audio learned from PMTs */
    private array $ac3Pids = [];

    /** @var array<int, SyncFrameSampler> active samplers keyed by PID */
    private array $ac3Samplers = [];

    /** @var array<int, SyncFrame> captured first sync frame per AC-3 PID */
    private array $ac3SyncFrames = [];

    /** @var int[] MPEG-2 video PIDs learned from PMTs */
    private array $mpeg2VideoPids = [];

    /** @var array<int, SequenceHeaderSampler> active MPEG-2 samplers keyed by PID */
    private array $mpeg2Samplers = [];

    /** @var array<int, SequenceHeader> captured sequence headers per MPEG-2 PID */
    private array $mpeg2Headers = [];

    /** @var int[] AVC video PIDs learned from PMTs */
    private array $avcVideoPids = [];

    /** @var array<int, SequenceParameterSetSampler> active AVC samplers keyed by PID */
    private array $avcSamplers = [];

    /** @var array<int, SequenceParameterSet> captured SPS per AVC PID */
    private array $avcSpsByPid = [];

    /** @var array<int, bool> EIT PIDs that have completed at least one transmission cycle */
    private array $eitPidCycled = [];

    /** @var array<int, bool> ETT PIDs that have completed at least one transmission cycle */
    private array $ettPidCycled = [];

    /** Every packet that passed the sync-byte check, regardless of acceptance. */
    private int $totalPacketsSeen = 0;

    /** Count of packets observed on the PSIP base PID (0x1FFB). */
    private int $psipPacketsSeen = 0;

    public function analyze(string $packet): int
    {
        $reader = new BinaryReader($packet);

        if ($reader->uint8() !== self::SYNC_BYTE) {
            return self::RETURN_TYPE_CONTINUE;
        }

        $transportError = $reader->bits(1); // transport_error_indicator
        $pusi = $reader->bits(1);         // payload_unit_start_indicator
        $reader->skipBits(1);             // transport_priority
        $pid = $reader->bits(13);

        if ($transportError === 1) {
            // Tuner/mux flagged this packet as corrupt - contents are unreliable.
            return self::RETURN_TYPE_CONTINUE;
        }

        ++$this->totalPacketsSeen;

        if ($pid === self::PID_PSIP) {
            ++$this->psipPacketsSeen;
        }

        if ($this->isPendingElementaryStreamPid($pid)) {
            $this->sampleElementaryStream($pid, $pusi === 1, $packet);

            return $this->isComplete() ? self::RETURN_TYPE_DONE : self::RETURN_TYPE_CONTINUE;
        }

        if (!$this->isAcceptedPid($pid)) {
            return self::RETURN_TYPE_CONTINUE;
        }

        ++$this->counter;

        $reader->skipBits(4);             // transport_scrambling_control(2) + adaptation_field_control(2)
        $continuityCounter = $reader->bits(4);

        if ($pusi === 1) {
            $this->startSection($reader, $packet, $pid, $continuityCounter);
        } else {
            $this->continueSection($pid, $continuityCounter, substr($packet, 4));
        }

        if (isset($this->inProgress[$pid]) && $this->inProgress[$pid]->isBufferComplete()) {
            $this->finalize($pid);
        }

        return $this->isComplete() ? self::RETURN_TYPE_DONE : self::RETURN_TYPE_CONTINUE;
    }

    private function isPendingElementaryStreamPid(int $pid): bool
    {
        if (in_array($pid, $this->ac3Pids, true) && !isset($this->ac3SyncFrames[$pid])) {
            return true;
        }

        if (in_array($pid, $this->mpeg2VideoPids, true) && !isset($this->mpeg2Headers[$pid])) {
            return true;
        }

        return in_array($pid, $this->avcVideoPids, true) && !isset($this->avcSpsByPid[$pid]);
    }

    private function sampleElementaryStream(int $pid, bool $payloadUnitStart, string $packet): void
    {
        $payload = $this->extractTsPayload($packet);

        if ($payload === null) {
            return;
        }

        if (in_array($pid, $this->ac3Pids, true)) {
            $this->sampleAc3($pid, $payloadUnitStart, $payload);

            return;
        }

        if (in_array($pid, $this->mpeg2VideoPids, true)) {
            $this->sampleMpeg2Video($pid, $payloadUnitStart, $payload);

            return;
        }

        if (in_array($pid, $this->avcVideoPids, true)) {
            $this->sampleAvcVideo($pid, $payloadUnitStart, $payload);
        }
    }

    /** Strip TS header (and any adaptation field) to return raw payload bytes. */
    private function extractTsPayload(string $packet): ?string
    {
        $b3        = ord($packet[3]);
        $adaptCtrl = ($b3 >> 4) & 0x3;

        if ($adaptCtrl === 0 || $adaptCtrl === 2) {
            // Reserved (0) or adaptation-only (2) - no payload bytes available.
            return null;
        }

        $offset = 4;

        if ($adaptCtrl === 3) {
            $adaptLen = ord($packet[4]);
            $offset   = 5 + $adaptLen;
        }

        if ($offset >= 188) {
            return null;
        }

        return substr($packet, $offset);
    }

    private function sampleAc3(int $pid, bool $payloadUnitStart, string $payload): void
    {
        $sampler = $this->ac3Samplers[$pid] ??= new SyncFrameSampler();
        $sampler->feed($payload, $payloadUnitStart);

        $frame = $sampler->getFrame();

        if ($frame === null) {
            return;
        }

        $this->ac3SyncFrames[$pid] = $frame;
        unset($this->ac3Samplers[$pid]);
    }

    private function sampleMpeg2Video(int $pid, bool $payloadUnitStart, string $payload): void
    {
        $sampler = $this->mpeg2Samplers[$pid] ??= new SequenceHeaderSampler();
        $sampler->feed($payload, $payloadUnitStart);

        $header = $sampler->getHeader();

        if ($header === null) {
            return;
        }

        $this->mpeg2Headers[$pid] = $header;
        unset($this->mpeg2Samplers[$pid]);
    }

    private function sampleAvcVideo(int $pid, bool $payloadUnitStart, string $payload): void
    {
        $sampler = $this->avcSamplers[$pid] ??= new SequenceParameterSetSampler();
        $sampler->feed($payload, $payloadUnitStart);

        $sps = $sampler->getSps();

        if ($sps === null) {
            return;
        }

        $this->avcSpsByPid[$pid] = $sps;
        unset($this->avcSamplers[$pid]);
    }

    private function isAcceptedPid(int $pid): bool
    {
        return $pid === self::PID_PSIP
            || $pid === self::PID_PAT
            || in_array($pid, $this->eitPids, true)
            || in_array($pid, $this->ettPids, true)
            || in_array($pid, $this->pmtPids, true);
    }

    private function startSection(BinaryReader $reader, string $packet, int $pid, int $cc): void
    {
        $pointerField = $reader->uint8();
        $reader->skipBytes($pointerField);

        $tableId = $reader->uint8();
        $reader->skipBits(4); // section_syntax_indicator(1) + private_indicator(1) + reserved(2)
        $sectionLength = $reader->bits(12);

        $payloadStart = $reader->bitPosition() >> 3;
        $payload      = substr($packet, $payloadStart);

        $section = $this->createSection($pid, $tableId, $sectionLength, $cc, $payload);
        if ($section === null) {
            unset($this->inProgress[$pid]);
            return;
        }

        $this->inProgress[$pid] = $section;
    }

    private function continueSection(int $pid, int $cc, string $payload): void
    {
        if (!isset($this->inProgress[$pid])) {
            return;
        }
        if ($cc !== $this->inProgress[$pid]->getNextSection()) {
            unset($this->inProgress[$pid]); // Drop on CC discontinuity.
            return;
        }

        $this->inProgress[$pid]->appendToBuffer($payload);
    }

    private function createSection(int $pid, int $tableId, int $sectionLength, int $cc, string $payload): ?SectionTable
    {
        // PAT and PMT are PID-scoped: same table_id space as other transport tables,
        // disambiguated by the PID they arrive on.
        if ($pid === self::PID_PAT && $tableId === self::TABLE_ID_PAT) {
            return $this->programAssociationTable === null
                ? new ProgramAssociationTable($sectionLength, $cc, $payload)
                : null;
        }
        if (in_array($pid, $this->pmtPids, true) && $tableId === self::TABLE_ID_PMT) {
            return !isset($this->programMapTables[$pid])
                ? new ProgramMapTable($sectionLength, $cc, $payload)
                : null;
        }

        switch ($tableId) {
            case self::TABLE_ID_MGT:
                return $this->masterGuideTable === null
                    ? new MasterGuideTable($sectionLength, $cc, $payload)
                    : null;

            case self::TABLE_ID_TVCT:
                return $this->virtualChannelTable === null
                    ? new VirtualChannelTable($sectionLength, $cc, $payload)
                    : null;

            case self::TABLE_ID_STT:
                return $this->systemTimeTable === null
                    ? new SystemTimeTable($sectionLength, $cc, $payload)
                    : null;

            case self::TABLE_ID_EIT:
                return new EventInformationTable($sectionLength, $cc, $payload);

            case self::TABLE_ID_ETT:
                return new ExtendedTextTable($sectionLength, $cc, $payload);

            default:
                return null;
        }
    }

    private function finalize(int $pid): void
    {
        $section = $this->inProgress[$pid];
        unset($this->inProgress[$pid]);

        $section->parse();
        $this->stash($pid, $section);
    }

    private function stash(int $pid, SectionTable $section): void
    {
        if ($section instanceof ProgramAssociationTable) {
            $this->programAssociationTable = $section;
            $this->registerPmtPids();
            return;
        }
        if ($section instanceof ProgramMapTable) {
            $this->programMapTables[$pid] = $section;
            $this->registerElementaryStreamPids($section);
            return;
        }
        if ($section instanceof MasterGuideTable) {
            $this->masterGuideTable = $section;
            $this->registerPidsFromMgt();
            return;
        }
        if ($section instanceof VirtualChannelTable) {
            $this->virtualChannelTable = $section;
            return;
        }
        if ($section instanceof SystemTimeTable) {
            $this->systemTimeTable = $section;
            return;
        }
        if ($section instanceof EventInformationTable) {
            $this->stashEit($pid, $section);
            return;
        }
        if ($section instanceof ExtendedTextTable) {
            $this->stashEtt($pid, $section);
        }
    }

    private function registerElementaryStreamPids(ProgramMapTable $pmt): void
    {
        foreach ($pmt->getStreams() as $stream) {
            $pid = $stream->getPid();

            switch ($stream->getType()) {
                case Stream::TYPE_AC3:
                    $this->addUnique($this->ac3Pids, $pid);
                    break;

                case Stream::TYPE_MPEG2_VIDEO:
                    $this->addUnique($this->mpeg2VideoPids, $pid);
                    break;

                case Stream::TYPE_AVC_VIDEO:
                    $this->addUnique($this->avcVideoPids, $pid);
                    break;
            }
        }
    }

    /**
     * @param int[] $pids
     */
    private function addUnique(array &$pids, int $pid): void
    {
        if (!in_array($pid, $pids, true)) {
            $pids[] = $pid;
        }
    }

    private function registerPmtPids(): void
    {
        if ($this->programAssociationTable === null) {
            return;
        }
        $this->pmtPids = array_values(array_unique(
            array_values($this->programAssociationTable->getPrograms())
        ));
    }

    private function registerPidsFromMgt(): void
    {
        if ($this->masterGuideTable === null) {
            return;
        }
        foreach ($this->masterGuideTable->getEntries() as $entry) {
            $type = $entry->getTableType();
            if ($type >= self::MGT_TABLE_TYPE_EIT_FIRST && $type <= self::MGT_TABLE_TYPE_EIT_LAST) {
                $this->eitPids[] = $entry->getPid();
            } elseif ($type >= self::MGT_TABLE_TYPE_ETT_FIRST && $type <= self::MGT_TABLE_TYPE_ETT_LAST) {
                $this->ettPids[] = $entry->getPid();
            } elseif ($type === self::MGT_TABLE_TYPE_CHANNEL_ETT) {
                $this->ettPids[] = $entry->getPid();
            }
        }
        $this->eitPids = array_values(array_unique($this->eitPids));
        $this->ettPids = array_values(array_unique($this->ettPids));
    }

    private function stashEit(int $pid, EventInformationTable $eit): void
    {
        $sourceId      = $eit->getSourceId();
        $sectionNumber = $eit->getSectionNumber();
        if (isset($this->eitSections[$pid][$sourceId][$sectionNumber])) {
            // Duplicate: this PID has completed at least one transmission cycle.
            $this->eitPidCycled[$pid] = true;

            return;
        }
        $this->eitSections[$pid][$sourceId][$sectionNumber] = $eit;
    }

    private function stashEtt(int $pid, ExtendedTextTable $ett): void
    {
        $sectionNumber = $ett->getSectionNumber();
        if (isset($this->ettSections[$pid][$sectionNumber])) {
            $this->ettPidCycled[$pid] = true;

            return;
        }
        $this->ettSections[$pid][$sectionNumber] = $ett;

        $text = $ett->getExtendedTextMessage();
        if ($text === '') {
            return;
        }

        if ($ett->isEventEtm()) {
            $this->eventDescriptions[$ett->getSourceId()][$ett->getEventId()] = $text;
            return;
        }
        if ($ett->isChannelEtm()) {
            $this->channelDescriptions[$ett->getSourceId()] = $text;
        }
    }

    private function isComplete(): bool
    {
        if ($this->programAssociationTable === null) {
            return false;
        }

        foreach ($this->pmtPids as $pmtPid) {
            if (!isset($this->programMapTables[$pmtPid])) {
                return false;
            }
        }

        if (!$this->areElementarySamplesReadyOrTimedOut()) {
            return false;
        }

        // HDHomeRun's /auto/vXX.Y URL returns a stream demuxed down to one program -
        // PAT and PMT only, no PSIP. Once we've waited long enough to be sure no
        // PSIP is forthcoming, we have everything this stream will ever give us.
        if ($this->isSingleProgramStream()) {
            return true;
        }

        if ($this->masterGuideTable === null
            || $this->virtualChannelTable === null
            || $this->systemTimeTable === null) {
            return false;
        }

        // Each EIT/ETT PID must have completed at least one transmission cycle
        // (signalled by a duplicate section), and every (pid, sourceId) pair
        // we've started receiving must have all its sections. Pairs the broadcaster
        // never sends are not waited for.
        foreach ($this->eitPids as $eitPid) {
            if (!($this->eitPidCycled[$eitPid] ?? false)) {
                return false;
            }

            foreach ($this->eitSections[$eitPid] ?? [] as $bySource) {
                if (!$this->hasAllSections($bySource)) {
                    return false;
                }
            }
        }

        foreach ($this->ettPids as $ettPid) {
            if (!($this->ettPidCycled[$ettPid] ?? false)) {
                return false;
            }

            if (!$this->hasAllSections($this->ettSections[$ettPid] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int, EventInformationTable|ExtendedTextTable>|null $sections
     */
    private function hasAllSections(?array $sections): bool
    {
        if ($sections === null || $sections === []) {
            return false;
        }

        /** @var EventInformationTable|ExtendedTextTable $first */
        $first       = reset($sections);
        $lastSection = $first->getLastSectionNumber();
        for ($i = 0; $i <= $lastSection; $i++) {
            if (!isset($sections[$i])) {
                return false;
            }
        }

        return true;
    }

    public function getCounter(): int
    {
        return $this->counter;
    }

    /**
     * True once we're confident the stream carries no PSIP - typically a single
     * program demuxed by the tuner (e.g. HDHomeRun's /auto/vXX.Y URL form).
     */
    public function isSingleProgramStream(): bool
    {
        return $this->totalPacketsSeen >= self::SINGLE_PROGRAM_THRESHOLD_PACKETS
            && $this->psipPacketsSeen === 0;
    }

    private function areElementarySamplesReadyOrTimedOut(): bool
    {
        if ($this->totalPacketsSeen >= self::ELEMENTARY_SAMPLE_GIVE_UP_PACKETS) {
            return true;
        }

        foreach ($this->ac3Pids as $pid) {
            if (!isset($this->ac3SyncFrames[$pid])) {
                return false;
            }
        }

        foreach ($this->mpeg2VideoPids as $pid) {
            if (!isset($this->mpeg2Headers[$pid])) {
                return false;
            }
        }

        foreach ($this->avcVideoPids as $pid) {
            if (!isset($this->avcSpsByPid[$pid])) {
                return false;
            }
        }

        return true;
    }

    public function getMasterGuideTable(): ?MasterGuideTable
    {
        return $this->masterGuideTable;
    }

    public function getVirtualChannelTable(): ?VirtualChannelTable
    {
        return $this->virtualChannelTable;
    }

    public function getSystemTimeTable(): ?SystemTimeTable
    {
        return $this->systemTimeTable;
    }

    public function getProgramAssociationTable(): ?ProgramAssociationTable
    {
        return $this->programAssociationTable;
    }

    public function getProgramMapTable(int $pmtPid): ?ProgramMapTable
    {
        return $this->programMapTables[$pmtPid] ?? null;
    }

    public function getAc3SyncFrame(int $pid): ?SyncFrame
    {
        return $this->ac3SyncFrames[$pid] ?? null;
    }

    public function getMpeg2SequenceHeader(int $pid): ?SequenceHeader
    {
        return $this->mpeg2Headers[$pid] ?? null;
    }

    public function getAvcSps(int $pid): ?SequenceParameterSet
    {
        return $this->avcSpsByPid[$pid] ?? null;
    }

    /**
     * Events for one source_id, gathered across every captured EIT PID,
     * deduped by event_id, sorted by start_time.
     *
     * @return Event[]
     */
    public function getEventsForSource(int $sourceId): array
    {
        $events = [];
        foreach ($this->eitSections as $bySource) {
            $sections = $bySource[$sourceId] ?? [];
            foreach ($sections as $section) {
                foreach ($section->getEvents() as $event) {
                    $events[$event->getEventId()] = $event;
                }
            }
        }

        if ($events === []) {
            return [];
        }

        usort($events, static function (Event $a, Event $b) {
            return $a->getStartTime() <=> $b->getStartTime();
        });

        return $events;
    }

    public function getEventDescription(int $sourceId, int $eventId): ?string
    {
        return $this->eventDescriptions[$sourceId][$eventId] ?? null;
    }

    public function getChannelDescription(int $sourceId): ?string
    {
        return $this->channelDescriptions[$sourceId] ?? null;
    }
}
