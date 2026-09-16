<?php declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave\Guide;

use Skywave\Hdhomerun\ChannelMap;
use Skywave\Hdhomerun\Exception\HdhomerunException;
use Skywave\Hdhomerun\StreamInfo;
use Skywave\Hdhomerun\StreamProgram;
use Skywave\Hdhomerun\Tuner;

/**
 * Finds the channels a tuner can receive, following libhdhomerun's channel scan
 * (hdhomerun_channelscan.c): tune each channel of the map, wait for lock, then poll the
 * program list until it is complete and has not changed for a second.
 */
class ChannelScanner
{
    private const LOCK_TIMEOUT      = 5.0;
    private const PROGRAMS_TIMEOUT  = 4.0;
    private const STABLE_SECONDS    = 1.0;
    private const POLL_MICROSECONDS = 250000;

    /**
     * Scan every channel of a channel map. The tuner is left untuned afterwards.
     *
     * @param callable(int, list<array<string, mixed>>): void|null $progress called after each channel
     * @return list<array{physical: int, program: int, virtual: string, name: string, tsid: ?int, encrypted: bool}>
     */
    public function scan(Tuner $tuner, string $channelMap, ?callable $progress = null): array
    {
        $found = [];

        try {
            $tuner->setChannelMap($channelMap);

            foreach (array_keys(ChannelMap::getChannels($channelMap)) as $physical) {
                $programs = $this->scanChannel($tuner, $physical);

                if ($progress !== null) {
                    $progress($physical, $programs);
                }

                array_push($found, ...$programs);
            }
        } finally {
            try {
                $tuner->setChannel('none');
            } catch (HdhomerunException $e) {
                // The scan result stands on its own.
            }
        }

        return $found;
    }

    /**
     * Programs a single physical channel carries; empty when nothing locks.
     *
     * @return list<array{physical: int, program: int, virtual: string, name: string, tsid: ?int, encrypted: bool}>
     */
    public function scanChannel(Tuner $tuner, int $physical): array
    {
        $tuner->setChannel("auto:$physical");

        if (!$tuner->waitForLock(self::LOCK_TIMEOUT)->isLockSupported()) {
            return [];
        }

        $deadline    = microtime(true) + self::PROGRAMS_TIMEOUT;
        $stableSince = microtime(true);
        $previous    = null;

        while (true) {
            $info = $tuner->getStreamInfo();

            if ($info->getRaw() !== $previous) {
                $previous    = $info->getRaw();
                $stableSince = microtime(true);
            }

            $now = microtime(true);

            if ((self::isComplete($info) && $now - $stableSince >= self::STABLE_SECONDS) || $now >= $deadline) {
                break;
            }

            usleep(self::POLL_MICROSECONDS);
        }

        $programs = [];

        foreach ($info->getPrograms() as $program) {
            if (!in_array($program->getType(), [StreamProgram::TYPE_NORMAL, StreamProgram::TYPE_ENCRYPTED], true)) {
                continue;
            }

            $programs[] = [
                'physical'  => $physical,
                'program'   => $program->getProgramNumber(),
                'virtual'   => $program->getVirtualChannel() !== '' ? $program->getVirtualChannel() : "$physical.{$program->getProgramNumber()}",
                'name'      => $program->getName(),
                'tsid'      => $info->getTransportStreamId(),
                'encrypted' => $program->getType() === StreamProgram::TYPE_ENCRYPTED,
            ];
        }

        return $programs;
    }

    /**
     * Same rules as libhdhomerun: at least one program, none still waiting for data, and
     * every normal program has a virtual channel number and a name.
     */
    private static function isComplete(StreamInfo $info): bool
    {
        if ($info->getPrograms() === []) {
            return false;
        }

        foreach ($info->getPrograms() as $program) {
            if ($program->getType() === StreamProgram::TYPE_NO_DATA) {
                return false;
            }

            if ($program->getType() === StreamProgram::TYPE_NORMAL && ($program->getVirtualMajor() === 0 || $program->getName() === '')) {
                return false;
            }
        }

        return true;
    }
}
