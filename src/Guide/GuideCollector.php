<?php

declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave\Guide;

use RuntimeException;
use Skywave\Hdhomerun\Device;
use Skywave\Hdhomerun\Exception\HdhomerunException;
use Skywave\Hdhomerun\Exception\StreamException;
use Skywave\Hdhomerun\StreamAnalyzer;
use Skywave\Hdhomerun\Tuner;
use Skywave\Report\JsonRenderer;
use Throwable;

/**
 * Fills the guide store from a device: a channel scan for the lineup, then the ATSC
 * guide tables (EIT and ETT, typically the next 12 hours) read from each physical
 * channel. Only idle tuners are used, so viewers are never interrupted.
 */
class GuideCollector
{
    /** Keep events for a day after they end, then drop them. */
    private const KEEP_ENDED_SECONDS = 86400;

    private GuideStore $store;
    private int $secondsPerChannel;
    private ChannelScanner $scanner;
    private StreamAnalyzer $analyzer;
    /** @var callable(string): void */
    private $log;
    /** @var callable(int): bool */
    private $isTunerAvailable;

    /**
     * @param int $secondsPerChannel longest time to read one channel; reading stops earlier
     *                               once every table has arrived
     * @param callable(string): void|null $log progress lines
     */
    public function __construct(GuideStore $store, int $secondsPerChannel = 30, ?callable $log = null, ?ChannelScanner $scanner = null, ?StreamAnalyzer $analyzer = null)
    {
        $this->store             = $store;
        $this->secondsPerChannel = max(5, $secondsPerChannel);
        $this->log               = $log ?? static function (string $line): void {
        };
        $this->scanner          = $scanner ?? new ChannelScanner();
        $this->analyzer         = $analyzer ?? new StreamAnalyzer();
        $this->isTunerAvailable = static fn (int $index): bool => true;
    }

    /**
     * Leave tuners alone that something else has claimed, e.g. a recording in progress.
     *
     * @param callable(int): bool $isTunerAvailable answers for a tuner index
     */
    public function skipTunersUnless(callable $isTunerAvailable): void
    {
        $this->isTunerAvailable = $isTunerAvailable;
    }

    /**
     * Scan for channels and replace the device's lineup.
     *
     * @return int programs found
     */
    public function scan(Device $device, string $channelMap): int
    {
        $host = $device->getHost();
        $run  = $this->store->startRun($host, 'scan');

        try {
            $tuner = self::findIdleTuner($device, $this->isTunerAvailable);

            if ($tuner === null) {
                throw new RuntimeException('Every tuner is in use');
            }

            ($this->log)("Scanning $channelMap on tuner {$tuner->getIndex()} of $host");

            $channels = $this->scanner->scan($tuner, $channelMap, function (int $physical, array $programs): void {
                if ($programs !== []) {
                    $names = array_map(fn (array $program) => "{$program['virtual']} {$program['name']}", $programs);
                    ($this->log)(sprintf('  channel %d: %s', $physical, implode(', ', $names)));
                }
            });

            // One request to the device serves both: which channels it calls high
            // definition, and which are ATSC 3.0. The latter are kept apart from the
            // lineup proper, since nothing here can tune them.
            $rows = self::lineupRows($device);
            $this->store->saveLineup($host, self::withHdFlags($channels, $rows));
            $this->store->saveAtsc3Lineup($host, self::atsc3Channels($rows));
            $this->store->finishRun($run, count($channels), 0);
            ($this->log)(sprintf('Scan finished: %d programs', count($channels)));

            return count($channels);
        } catch (Throwable $e) {
            $this->store->finishRun($run, 0, 0, $e->getMessage());

            throw $e;
        }
    }

    /**
     * Read the guide from every physical channel in the device's lineup, scanning first
     * when there is no lineup yet.
     *
     * @return array{channels: int, events: int}
     */
    public function collect(Device $device, string $channelMap): array
    {
        $host      = $device->getHost();
        $physicals = $this->physicalChannels($host);

        if ($physicals === []) {
            $this->scan($device, $channelMap);
            $physicals = $this->physicalChannels($host);
        }

        // The device knows which channels are high definition and which are ATSC 3.0, and
        // the broadcast says neither, so refresh both here rather than making people wait
        // for the next channel scan.
        $rows = self::lineupRows($device);
        $this->store->setHdFlags($host, self::hdByVirtual($rows));
        $this->store->saveAtsc3Lineup($host, self::atsc3Channels($rows));

        $run       = $this->store->startRun($host, 'collect');
        $startedAt = time();
        $totals    = ['channels' => 0, 'events' => 0];
        $errors    = [];

        try {
            foreach ($physicals as $physical) {
                $tuner = self::findIdleTuner($device, $this->isTunerAvailable);

                if ($tuner === null) {
                    $errors[] = "channel $physical: every tuner is in use";
                    ($this->log)(end($errors));

                    continue;
                }

                $url = StreamAnalyzer::tunerUrl($host, $tuner->getIndex(), $physical, $this->secondsPerChannel + 5);

                try {
                    $result = $this->analyzer->analyze($url, $this->secondsPerChannel);
                } catch (StreamException $e) {
                    // The device refuses the stream when a channel will not lock, which is
                    // ordinary for a weak one. The page wants the fact, the log wants the detail.
                    $errors[] = "channel $physical could not be read";
                    ($this->log)("channel $physical: {$e->getMessage()}");

                    continue;
                }

                $report = (new JsonRenderer())->render($result['parser']);
                $counts = $this->store->saveChannelGuide($host, $physical, $report['transportStreamId'], $report['channels']);

                $totals['channels'] += $counts['channels'];
                $totals['events'] += $counts['events'];

                ($this->log)(sprintf(
                    '  channel %d on tuner %d: %d channels, %d events in %.1fs%s',
                    $physical,
                    $tuner->getIndex(),
                    $counts['channels'],
                    $counts['events'],
                    $result['seconds'],
                    $result['complete'] ? '' : ' (tables incomplete when time ran out)'
                ));

                self::waitForRelease($tuner);
            }

            $this->store->pruneEvents(time() - self::KEEP_ENDED_SECONDS, $startedAt);
            $this->store->finishRun($run, $totals['channels'], $totals['events'], $errors === [] ? null : implode('; ', $errors));
            ($this->log)(sprintf('Guide updated: %d channels, %d events', $totals['channels'], $totals['events']));

            return $totals;
        } catch (Throwable $e) {
            $this->store->finishRun($run, $totals['channels'], $totals['events'], $e->getMessage());

            throw $e;
        }
    }

    /**
     * Mark the channels the device considers high definition.
     *
     * The device keeps its own lineup with a flag per channel, which costs one request and
     * no tuner time; the broadcast's own tables do not say. A device that will not answer
     * simply leaves every channel unflagged.
     *
     * @param list<array<string, mixed>> $channels
     * @return list<array<string, mixed>>
     */
    private static function withHdFlags(array $channels, array $rows): array
    {
        $hd = self::hdByVirtual($rows);

        return array_map(fn (array $channel) => $channel + ['hd' => $hd[$channel['virtual']] ?? false], $channels);
    }

    /**
     * Virtual channel number to whether the device calls it high definition.
     *
     * @param list<array<string, mixed>> $rows
     * @return array<string, bool>
     */
    private static function hdByVirtual(array $rows): array
    {
        $hd = [];

        foreach ($rows as $row) {
            $virtual = (string) ($row['GuideNumber'] ?? '');

            if ($virtual !== '') {
                $hd[$virtual] = ($row['HD'] ?? 0) === 1;
            }
        }

        return $hd;
    }

    /**
     * The device's own lineup, straight from lineup.json. A device that will not answer
     * leaves every channel unflagged and reports no ATSC 3.0 stations.
     *
     * @return list<array<string, mixed>>
     */
    private static function lineupRows(Device $device): array
    {
        $lineup = @file_get_contents(
            sprintf('http://%s/lineup.json', $device->getHost()),
            false,
            stream_context_create(['http' => ['timeout' => 5.0]])
        );
        $rows = $lineup === false ? null : json_decode($lineup, true);

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /**
     * The ATSC 3.0 stations the device can see.
     *
     * A scan cannot find these: 3.0 carries ROUTE/DASH over ALP rather than an MPEG
     * transport stream, so there are no PSIP tables to read and no programme data to be
     * had. The device's own lineup is the only place they appear, and they are listed so
     * that what is on the air is visible, not because anything here can play them.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private static function atsc3Channels(array $rows): array
    {
        $channels = [];

        foreach ($rows as $row) {
            $virtual = (string) ($row['GuideNumber'] ?? '');
            $video   = (string) ($row['VideoCodec'] ?? '');
            $audio   = (string) ($row['AudioCodec'] ?? '');

            if ($virtual === '' || ($video !== 'HEVC' && $audio !== 'AC4')) {
                continue;
            }

            // The device writes "None" rather than omitting the flag.
            $drm = $row['DRM'] ?? null;

            $channels[] = [
                'virtual'    => $virtual,
                'name'       => (string) ($row['GuideName'] ?? $virtual),
                'videoCodec' => $video === '' ? null : $video,
                'audioCodec' => $audio === '' ? null : $audio,
                'drm'        => !in_array($drm, [null, 'None', 0, '0', false], true),
                'hd'         => ($row['HD'] ?? 0) === 1,
            ];
        }

        return $channels;
    }

    /**
     * A tuner nobody is using: not tuned, not streaming, not locked, and not claimed by
     * something this device cannot see, such as a recording about to start.
     *
     * @param callable(int): bool|null $isAvailable answers for a tuner index
     */
    public static function findIdleTuner(Device $device, ?callable $isAvailable = null): ?Tuner
    {
        foreach ($device->getTuners() as $tuner) {
            if ($isAvailable !== null && !$isAvailable($tuner->getIndex())) {
                continue;
            }

            try {
                // A tuner keeps its last channel long after anyone has finished with it,
                // and reading a channel retunes it anyway. What makes it unavailable is
                // somebody streaming from it or holding its lock.
                if ($tuner->getTarget() === 'none' && in_array($tuner->getLockOwner(), [null, 'none'], true)) {
                    return $tuner;
                }
            } catch (HdhomerunException $e) {
                continue;
            }
        }

        return null;
    }

    /**
     * @return int[]
     */
    private function physicalChannels(string $host): array
    {
        $physicals = array_values(array_unique(array_column($this->store->getLineup($host), 'physical')));
        sort($physicals);

        return $physicals;
    }

    /**
     * The device releases a tuner a moment after its HTTP stream closes; wait so the
     * next channel does not find it still busy.
     */
    private static function waitForRelease(Tuner $tuner): void
    {
        $deadline = microtime(true) + 3.0;

        while (microtime(true) < $deadline) {
            try {
                if ($tuner->getTarget() === 'none') {
                    return;
                }
            } catch (HdhomerunException $e) {
                return;
            }

            usleep(100000);
        }
    }
}
