<?php

declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave\Web;

use InvalidArgumentException;
use RuntimeException;
use Skywave\Dvr\Recorder;
use Skywave\Dvr\RecordingPlayback;
use Skywave\Dvr\RecordingStore;
use Skywave\Dvr\SeriesRules;
use Skywave\Dvr\TunerReservations;
use Skywave\Guide\ChannelLogos;
use Skywave\Guide\GuideJobs;
use Skywave\Guide\GuideStore;
use Skywave\Guide\ProgrammeArtwork;
use Skywave\Hdhomerun\ChannelMap;
use Skywave\Hdhomerun\ControlClient;
use Skywave\Hdhomerun\Device;
use Skywave\Hdhomerun\DiscoveredDevice;
use Skywave\Hdhomerun\Discovery;
use Skywave\Hdhomerun\Exception\DeviceErrorException;
use Skywave\Hdhomerun\Exception\HdhomerunException;
use Skywave\Hdhomerun\Exception\StreamException;
use Skywave\Hdhomerun\Packet;
use Skywave\Hdhomerun\StreamAnalyzer;
use Skywave\Hdhomerun\StreamProgram;
use Skywave\Hdhomerun\Tuner;
use Skywave\Report\JsonRenderer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * JSON API behind the web UI.
 *
 *   GET    /api/devices?hosts=ip,ip                     discovered and remembered devices, plus the listed hosts
 *   POST   /api/devices        {"host": "<ip>"}         remember a device for every browser
 *   DELETE /api/devices/{ip}                            forget a remembered device
 *   GET    /api/devices/{ip}                            one device
 *   GET /api/devices/{ip}/tuners/{n}                    tuner status, signal and programs
 *   PUT /api/devices/{ip}/tuners/{n}/channel            {"channel": "auto:33"} or {"channel": "none"}
 *   PUT /api/devices/{ip}/tuners/{n}/channelmap         {"channelmap": "us-bcast"}
 *   GET /api/devices/{ip}/tuners/{n}/analysis?seconds=N analyze the tuned channel's live stream
 *   GET /api/channelmaps/{name}                         channel numbers and frequencies
 *
 *   POST   /api/devices/{ip}/tuners/{n}/stream  {"program": 3, "viewer": "<id>"}   watch a program
 *   POST   /api/streams/atsc3  {"device": "<ip>", "virtual": "102.1", "viewer": "<id>"}
 *   GET    /api/streams                                  active playback sessions
 *   GET    /api/streams/{id}?viewer=<id>                 session state; keeps the viewer watching
 *   DELETE /api/streams/{id}?viewer=<id>                 stop watching (POST .../leave for sendBeacon)
 *
 *   GET  /api/guide?device=<ip>&from=<unix>&hours=4      stored guide for a time window
 *   POST /api/guide/scan     {"device": "<ip>"}          find channels in the background
 *   POST /api/guide/collect  {"device": "<ip>"}          read guide data in the background
 *
 *   GET    /api/recordings?device=<ip>                   schedules, recordings and the folder
 *   POST   /api/recordings                               schedule one showing from the guide
 *   POST   /api/recordings/{id}/stop                     ask the recorder to stop it, keeping the file
 *   DELETE /api/recordings/{id}                          delete a recording and its file
 *   DELETE /api/recordings/schedules/{id}                cancel a schedule
 *
 *   POST   /api/recordings/{id}/play    {"viewer": "<id>"}   watch a recording, converting if needed
 *   GET    /api/recordings/{id}/play?viewer=<id>             how far the conversion has got
 *   DELETE /api/recordings/{id}/play?viewer=<id>             stop watching
 *
 * Errors come back as {"error": "..."} with 400 (bad input), 404 (unknown route),
 * 409 (the device refused, e.g. tuner locked or no signal) or 502 (device unreachable).
 *
 * Devices are addressed by IPv4 address, limited to private, loopback and link-local
 * ranges so the API cannot be used to make this server reach arbitrary hosts.
 */
class Api
{
    private const DEFAULT_ANALYSIS_SECONDS = 15;
    private const MAX_ANALYSIS_SECONDS     = 60;
    private const DEFAULT_GUIDE_HOURS      = 4;
    private const MAX_GUIDE_HOURS          = 24;

    private Discovery $discovery;
    /** @var string[] */
    private array $configuredHosts;
    private ?LiveStreams $streams;
    private ?GuideStore $guide;
    private ?GuideJobs $guideJobs;
    private ?RecordingStore $recordings;
    private ?Recorder $recorder;
    private ?TunerReservations $reservations;
    private ?RecordingPlayback $playback;

    private ?Logs $logs;

    private ?SeriesRules $series;

    /**
     * @param string[] $configuredHosts devices to list even when broadcast discovery cannot reach them
     * @param LiveStreams|null $streams live playback, or null to disable it
     * @param GuideStore|null $guide program guide, or null to disable it
     * @param GuideJobs|null $guideJobs background guide scans and collections
     * @param RecordingStore|null $recordings scheduled and finished recordings, or null to disable them
     * @param Recorder|null $recorder stops recordings and finds their files
     * @param TunerReservations|null $reservations tuners a recording is using
     * @param RecordingPlayback|null $playback watching recordings back
     */
    public function __construct(
        Discovery $discovery,
        array $configuredHosts = [],
        ?LiveStreams $streams = null,
        ?GuideStore $guide = null,
        ?GuideJobs $guideJobs = null,
        ?RecordingStore $recordings = null,
        ?Recorder $recorder = null,
        ?TunerReservations $reservations = null,
        ?RecordingPlayback $playback = null,
        ?Logs $logs = null,
        ?SeriesRules $series = null
    ) {
        $this->discovery       = $discovery;
        $this->configuredHosts = $configuredHosts;
        $this->streams         = $streams;
        $this->guide           = $guide;
        $this->guideJobs       = $guideJobs;
        $this->recordings      = $recordings;
        $this->recorder        = $recorder;
        $this->reservations    = $reservations;
        $this->playback        = $playback;
        $this->logs            = $logs;
        $this->series          = $series;
    }

    public function handle(Request $request): JsonResponse
    {
        try {
            return self::json($this->route($request));
        } catch (ApiException $e) {
            return self::json(['error' => $e->getMessage()], $e->getStatus());
        } catch (DeviceErrorException $e) {
            return self::json(['error' => $e->getDeviceMessage()], 409);
        } catch (StreamException $e) {
            return self::json(['error' => $e->getMessage()], $e->isRefused() ? 409 : 502);
        } catch (HdhomerunException $e) {
            return self::json(['error' => $e->getMessage()], 502);
        } catch (InvalidArgumentException $e) {
            return self::json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function route(Request $request): array
    {
        $method = $request->getMethod();
        $path   = '/' . trim($request->getPathInfo(), '/');

        if ($path === '/api/devices') {
            if ($method === 'POST') {
                return $this->rememberDevice(self::jsonBody($request)['host'] ?? null);
            }

            self::requireMethod($method, 'GET');

            return $this->listDevices((string) $request->query->get('hosts', ''));
        }

        // Before the device pattern below, which would read "scan" as an address.
        if ($path === '/api/devices/scan') {
            self::requireMethod($method, 'POST');

            return $this->scanForDevices($request);
        }

        if (preg_match('#^/api/channelmaps/([a-z]{2}-[a-z]+)$#', $path, $match)) {
            self::requireMethod($method, 'GET');

            return $this->describeChannelMap($match[1]);
        }

        if ($path === '/api/streams' || str_starts_with($path, '/api/streams/')) {
            return $this->routeStreams($method, $path, $request);
        }

        if ($path === '/api/guide') {
            self::requireMethod($method, 'GET');

            return $this->describeGuide($request);
        }

        if (preg_match('#^/api/guide/(scan|collect)$#', $path, $match)) {
            self::requireMethod($method, 'POST');

            return $this->startGuideJob($match[1], self::jsonBody($request)['device'] ?? null);
        }

        if ($path === '/api/recordings' || str_starts_with($path, '/api/recordings/')) {
            return $this->routeRecordings($method, $path, $request);
        }

        if ($path === '/api/logs') {
            self::requireMethod($method, 'GET');

            return $this->listLogs();
        }

        if (preg_match('#^/api/logs/([A-Za-z0-9-]+)$#', $path, $match)) {
            self::requireMethod($method, 'GET');

            return $this->readLog($match[1], (int) $request->query->get('lines', '200'));
        }

        if (!preg_match('#^/api/devices/([^/]+)(?:/tuners/(\d+)(?:/(channel|channelmap|analysis|stream))?)?$#', $path, $match)) {
            throw new ApiException('Not found', 404);
        }

        $host   = self::validateHost($match[1]);
        $action = $match[3] ?? '';

        if (($match[2] ?? '') === '') {
            if ($method === 'DELETE') {
                return $this->forgetDevice($host);
            }

            self::requireMethod($method, 'GET');

            return $this->describeDevice(Device::at($host, $this->discovery), 'manual');
        }

        $tuner = (new Device(new ControlClient($host)))->getTuner((int) $match[2]);

        if ($action === 'channel' || $action === 'stream') {
            $this->guardTunerFree($host, (int) $match[2]);
        }

        switch ($action) {
            case 'channel':
                self::requireMethod($method, 'PUT');

                return $this->setChannel($tuner, self::jsonBody($request)['channel'] ?? null);

            case 'channelmap':
                self::requireMethod($method, 'PUT');

                return $this->setChannelMap($tuner, self::jsonBody($request)['channelmap'] ?? null);

            case 'analysis':
                self::requireMethod($method, 'GET');
                $seconds = (int) $request->query->get('seconds', (string) self::DEFAULT_ANALYSIS_SECONDS);

                return $this->analyze($host, $tuner, max(1, min(self::MAX_ANALYSIS_SECONDS, $seconds)));

            case 'stream':
                self::requireMethod($method, 'POST');

                return $this->startStream($host, $tuner, self::jsonBody($request));

            default:
                self::requireMethod($method, 'GET');

                return $this->describeTuner($tuner, $host);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function listDevices(string $hostsParameter): array
    {
        /** @var array<string, array{0: string, 1: ?DiscoveredDevice, 2: string}> $found [host, discovery reply, source] */
        $found = [];

        foreach ($this->discovery->findDevices([Packet::DEVICE_TYPE_TUNER]) as $discovered) {
            $found[$discovered->getDeviceIdHex()] = [$discovered->getIp(), $discovered, 'discovered'];
        }

        // Devices added from any browser, the ones configured for this server, and whatever
        // the caller asked about; a browser that has not caught up still sees its own.
        $requested = array_merge($this->configuredHosts, $this->rememberedDevices(), explode(',', $hostsParameter));
        $hosts     = array_unique(array_filter(array_map('trim', $requested), fn (string $host) => $host !== ''));
        $invalid   = [];

        foreach ($hosts as $host) {
            // One bad entry must not hide the devices that do work.
            try {
                self::validateHost($host);
            } catch (ApiException $e) {
                $invalid[] = ['host' => $host, 'source' => 'manual', 'deviceId' => null, 'error' => $e->getMessage()];

                continue;
            }

            if (in_array($host, array_column($found, 0), true)) {
                continue;
            }

            $discovered = $this->discovery->findDeviceAt($host);

            if ($discovered !== null && isset($found[$discovered->getDeviceIdHex()])) {
                continue;
            }

            // A device this server is configured with cannot be removed from the page: it
            // comes back on the next refresh. Saying so lets the page stop offering to.
            $source = in_array($host, $this->configuredHosts, true) ? 'configured' : 'manual';

            $found[$discovered === null ? "host:$host" : $discovered->getDeviceIdHex()] = [$host, $discovered, $source];
        }

        $devices = [];

        foreach ($found as [$host, $discovered, $source]) {
            try {
                $devices[] = $this->describeDevice(new Device(new ControlClient($host, Packet::PORT, 2.0), $discovered), $source);
            } catch (HdhomerunException $e) {
                $devices[] = [
                    'host'     => $host,
                    'source'   => $source,
                    'deviceId' => $discovered === null ? null : $discovered->getDeviceIdHex(),
                    'error'    => $e->getMessage(),
                ];
            }
        }

        return ['devices' => array_merge($devices, $invalid)];
    }

    /**
     * @return array<string, mixed>
     */
    private function describeDevice(Device $device, string $source): array
    {
        $discovered = $device->getDiscovered();

        return [
            'host'          => $device->getHost(),
            'source'        => $source,
            'deviceId'      => $discovered === null ? null : $discovered->getDeviceIdHex(),
            'model'         => $device->getModel(),
            'hardwareModel' => $device->getHardwareModel(),
            'firmware'      => $device->getFirmwareVersion(),
            'tunerCount'    => $device->getTunerCount(),
            'legacy'        => $discovered === null ? null : $discovered->isLegacy(),
            'baseUrl'       => $discovered === null ? null : $discovered->getBaseUrl(),
            'lineupUrl'     => $discovered === null ? null : $discovered->getLineupUrl(),
            'channelMaps'   => $device->getChannelMaps(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function describeTuner(Tuner $tuner, ?string $host = null): array
    {
        $status  = $tuner->getStatus();
        $locked  = $status->isLockSupported();
        $virtual = $locked ? $tuner->getVirtualStatus() : null;
        $info    = $locked ? $tuner->getStreamInfo() : null;
        $held    = $host === null || $this->reservations === null ? null : $this->reservations->find($host, $tuner->getIndex());

        return [
            'index' => $tuner->getIndex(),
            // What this application is using the tuner for, which the device cannot know.
            'reservedBy'      => $held === null ? null : $held['label'],
            'channel'         => $status->getChannel(),
            'physicalChannel' => self::physicalChannel($status->getChannel()),
            'channelMap'      => $tuner->getChannelMap(),
            'lock'            => $status->getLock(),
            'locked'          => $locked,
            'signalPresent'   => $status->isSignalPresent(),
            'signal'          => [
                'strength'           => $status->getSignalStrength(),
                'strengthDbm'        => $status->getSignalStrengthDbm(),
                'strengthColor'      => $status->getSignalStrengthColor(),
                'quality'            => $status->getSignalToNoiseQuality(),
                'qualityDb'          => $status->getSignalToNoiseDb(),
                'qualityColor'       => $status->getSignalToNoiseQualityColor(),
                'symbolQuality'      => $status->getSymbolErrorQuality(),
                'symbolQualityColor' => $status->getSymbolErrorQualityColor(),
            ],
            'bitsPerSecond'    => $status->getBitsPerSecond(),
            'packetsPerSecond' => $status->getPacketsPerSecond(),
            'target'           => $tuner->getTarget(),
            'lockOwner'        => $tuner->getLockOwner(),
            'virtualChannel'   => $virtual === null || $virtual->getVirtualChannel() === '' ? null : [
                'channel' => $virtual->getVirtualChannel(),
                'name'    => $virtual->getName(),
            ],
            'streamInfo' => $info === null ? null : [
                'transportStreamId' => $info->getTransportStreamId(),
                'programs'          => array_map(fn (StreamProgram $program) => [
                    'number'         => $program->getProgramNumber(),
                    'virtualChannel' => $program->getVirtualChannel(),
                    'name'           => $program->getName(),
                    'type'           => $program->getType(),
                ], $info->getPrograms()),
            ],
            'raw' => $status->getRaw(),
        ];
    }

    /**
     * @param mixed $channel "auto:33", "none", or a bare channel number
     * @return array<string, mixed>
     */
    private function setChannel(Tuner $tuner, $channel): array
    {
        if (is_int($channel)) {
            $channel = "auto:$channel";
        }

        if (!is_string($channel) || !preg_match('/^(none|[a-z0-9]+:\d+)$/', $channel)) {
            throw new ApiException('Expected {"channel": "<modulation>:<number>"}, e.g. "auto:33", or "none"', 400);
        }

        $tuner->setChannel($channel);

        if ($channel !== 'none') {
            $tuner->waitForLock();
        }

        return $this->describeTuner($tuner);
    }

    /**
     * @param mixed $channelMap
     * @return array<string, mixed>
     */
    private function setChannelMap(Tuner $tuner, $channelMap): array
    {
        if (!is_string($channelMap) || !ChannelMap::exists($channelMap)) {
            throw new ApiException('Expected {"channelmap": "<name>"} with one of: ' . implode(', ', ChannelMap::getNames()), 400);
        }

        $tuner->setChannelMap($channelMap);

        return $this->describeTuner($tuner);
    }

    /**
     * @return array<string, mixed>
     */
    private function describeChannelMap(string $name): array
    {
        if (!ChannelMap::exists($name)) {
            throw new ApiException("Unknown channel map: $name", 404);
        }

        $channels = [];

        foreach (ChannelMap::getChannels($name) as $number => $frequency) {
            $channels[] = ['number' => $number, 'frequency' => $frequency];
        }

        return ['name' => $name, 'channels' => $channels];
    }

    /**
     * Read the tuned channel's full multiplex over the device's HTTP streaming port
     * and run it through the analyzer until the tables are complete or time runs out.
     *
     * @return array<string, mixed>
     */
    private function analyze(string $host, Tuner $tuner, int $seconds): array
    {
        $status   = $tuner->getStatus();
        $channel  = $status->getChannel();
        $physical = self::physicalChannel($channel);

        if ($physical === null) {
            throw new ApiException('The tuner is not tuned to a channel', 409);
        }

        if (!$status->isLockSupported()) {
            throw new ApiException("No signal lock on $channel", 409);
        }

        set_time_limit($seconds + 30);

        $targetBefore = $tuner->getTarget();

        // Ask for a little more than we will read so the device does not end the stream first.
        $url    = StreamAnalyzer::tunerUrl($host, $tuner->getIndex(), $physical, $seconds + 5);
        $result = (new StreamAnalyzer())->analyze($url, $seconds);

        TunerRelease::restoreChannel($tuner, $channel, $targetBefore);

        return [
            'source'         => $url,
            'channel'        => $channel,
            'complete'       => $result['complete'],
            'elapsedSeconds' => $result['seconds'],
            'bytes'          => $result['bytes'],
            'report'         => (new JsonRenderer())->render($result['parser']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function describeGuide(Request $request): array
    {
        $store  = $this->guideStore();
        $device = self::validateHost((string) $request->query->get('device', ''));
        $hours  = max(1, min(self::MAX_GUIDE_HOURS, (int) $request->query->get('hours', (string) self::DEFAULT_GUIDE_HOURS)));
        $from   = (string) $request->query->get('from', '');
        $from   = ctype_digit($from) ? (int) $from : intdiv(time(), 1800) * 1800;
        $to     = $from + $hours * 3600;

        return [
            'device'    => $device,
            'from'      => $from,
            'to'        => $to,
            'now'       => time(),
            'dataRange' => $store->getDataRange($device),
            // Which pictures exist is the server's business: a page that guesses asks for
            // six logos and five hundred posters that are not there, over and over.
            'channels' => self::withPictures($store->getGuide($from, $to, $device)),
            'running'  => $this->guideJobs !== null && $this->guideJobs->isRunning($device),
            'runs'     => $store->getRecentRuns(5, $device),
        ];
    }

    /**
     * @param mixed $device
     * @return array<string, mixed>
     */
    private function startGuideJob(string $command, $device): array
    {
        $this->guideStore();

        if ($this->guideJobs === null) {
            throw new ApiException('Guide collection is not enabled', 404);
        }

        $host = self::validateHost(is_string($device) ? $device : '');

        if (!$this->guideJobs->start($command, $host)) {
            throw new ApiException("A guide job is already running for $host", 409);
        }

        return ['started' => true, 'command' => $command, 'device' => $host];
    }

    /**
     * Look for tuners, asking every address on the networks the known ones live on.
     *
     * Broadcast discovery finds nothing from a container on Docker Desktop, which is why
     * scanning used to come back empty there. With no device known yet there is no network
     * to search, and one still has to be added by address first.
     *
     * @return array<string, mixed>
     */
    private function scanForDevices(Request $request): array
    {
        $found = [];

        foreach ($this->discovery->findDevices() as $device) {
            $found[$device->getIp()] = true;
        }

        $seeds = array_merge($this->configuredHosts, $this->rememberedDevices(), self::browsedNetwork($request));

        foreach (array_unique($seeds) as $host) {
            foreach ($this->discovery->findDevicesNear($host) as $device) {
                $found[$device->getIp()] = true;
            }
        }

        $hosts = array_keys($found);

        foreach ($hosts as $host) {
            // A reply from outside the private ranges is not something to remember.
            try {
                $this->guide?->addDevice(self::validateHost($host));
            } catch (ApiException $e) {
                continue;
            }
        }

        $networks = array_unique(array_map(
            fn (string $host) => implode('.', array_slice(explode('.', $host), 0, 3)),
            $seeds
        ));

        return ['found' => $hosts, 'searched' => count($networks)];
    }

    /**
     * The network the page was opened from, when the address says one.
     *
     * With no device known there is nothing to search, and a broadcast never leaves the
     * container on Docker Desktop, so scanning would find nothing at all. A browser that
     * reached this server at 192.168.1.253 has just named the network the tuner is on.
     *
     * @return string[]
     */
    private static function browsedNetwork(Request $request): array
    {
        $host = $request->getHost();

        // Loopback says only that the page was opened on this machine.
        if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || str_starts_with($host, '127.')) {
            return [];
        }

        try {
            return [self::validateHost($host)];
        } catch (ApiException $e) {
            // A public address is not a network to go probing.
            return [];
        }
    }

    /**
     * Keep a device somebody typed in, so every browser and phone sees it.
     *
     * @param mixed $host
     * @return array<string, mixed>
     */
    private function rememberDevice($host): array
    {
        $address = self::validateHost(is_string($host) ? $host : '');
        $this->guideStore()->addDevice($address);

        return ['added' => true, 'host' => $address];
    }

    /**
     * @return array<string, mixed>
     */
    private function forgetDevice(string $host): array
    {
        $this->guideStore()->removeDevice($host);

        return ['removed' => true, 'host' => $host];
    }

    /**
     * @return string[]
     */
    private function rememberedDevices(): array
    {
        return $this->guide === null ? [] : $this->guide->getDevices();
    }

    private function guideStore(): GuideStore
    {
        if ($this->guide === null) {
            throw new ApiException('The program guide is not enabled', 404);
        }

        return $this->guide;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function startStream(string $host, Tuner $tuner, array $body): array
    {
        $streams = $this->liveStreams();
        $program = $body['program'] ?? null;

        if (!is_int($program) || $program < 1) {
            throw new ApiException('Expected {"program": <program number>, "viewer": "<id>"}', 400);
        }

        $viewer   = self::validateViewer($body['viewer'] ?? null);
        $status   = $tuner->getStatus();
        $channel  = $status->getChannel();
        $physical = self::physicalChannel($channel);

        if ($physical === null) {
            throw new ApiException('The tuner is not tuned to a channel', 409);
        }

        if (!$status->isLockSupported()) {
            throw new ApiException("No signal lock on $channel", 409);
        }

        return $streams->join($host, $tuner->getIndex(), $channel, $physical, $program, $viewer, $tuner->getTarget(), $this->audioTracksFor($host, $physical, $program));
    }

    /**
     * The audio tracks the guide last saw on a programme, so live playback can offer a
     * second language. A channel the guide has never read simply gets the one track.
     *
     * @return list<array{language: ?string, name: ?string}>
     */
    private function audioTracksFor(string $host, int $physical, int $program): array
    {
        if ($this->guide === null) {
            return [];
        }

        foreach ($this->guide->getLineup($host) as $channel) {
            if ($channel['physical'] === $physical && $channel['program'] === $program) {
                return $channel['audio'] ?? [];
            }
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function routeStreams(string $method, string $path, Request $request): array
    {
        $streams = $this->liveStreams();

        if ($path === '/api/streams') {
            self::requireMethod($method, 'GET');

            return ['streams' => $streams->all()];
        }

        // A station whose media comes over the internet needs no tuner, so it does not go
        // through the device routes. The manifest is looked up here rather than sent by the
        // page: the page has no business knowing where a broadcaster serves its media.
        if ($path === '/api/streams/atsc3') {
            self::requireMethod($method, 'POST');

            return $this->startAtsc3Stream(self::jsonBody($request));
        }

        if (!preg_match('#^/api/streams/([a-f0-9]{16})(/leave)?$#', $path, $match)) {
            throw new ApiException('Not found', 404);
        }

        $viewer = $request->query->get('viewer');

        if (($match[2] ?? '') === '/leave') {
            self::requireMethod($method, 'POST');

            return $streams->leave($match[1], self::validateViewer($viewer));
        }

        switch ($method) {
            case 'GET':
                return $streams->status($match[1], $viewer === null ? null : self::validateViewer($viewer));

            case 'DELETE':
                return $streams->leave($match[1], self::validateViewer($viewer));

            default:
                throw new ApiException("Method $method not allowed, use GET or DELETE", 405);
        }
    }

    /**
     * Start playing an ATSC 3.0 station that carries its media over the internet.
     *
     * Only the picture is played: the audio is AC-4, which nothing here can decode. A
     * station that is encrypted, or that the broadcast never gave a manifest for, cannot be
     * played at all and says so rather than starting a transcoder that would never work.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function startAtsc3Stream(array $body): array
    {
        $device  = $body['device'] ?? null;
        $virtual = $body['virtual'] ?? null;
        $viewer  = self::validateViewer($body['viewer'] ?? null);

        if (!is_string($device) || !is_string($virtual)) {
            throw new ApiException('Expected {"device": "<ip>", "virtual": "<channel>", "viewer": "<id>"}', 400);
        }

        foreach ($this->guideStore()->getAtsc3Lineup(self::validateHost($device)) as $station) {
            if ($station['virtual'] !== $virtual) {
                continue;
            }

            if ($station['drm']) {
                throw new ApiException("$virtual is encrypted and cannot be played", 409);
            }

            if (!is_string($station['streamUrl']) || $station['streamUrl'] === '') {
                throw new ApiException("$virtual carries its media over the air, which cannot be played here", 409);
            }

            return $this->liveStreams()->joinUrl($station['streamUrl'], $virtual, $viewer);
        }

        throw new ApiException("No such ATSC 3.0 station: $virtual", 404);
    }

    private function liveStreams(): LiveStreams
    {
        if ($this->streams === null) {
            throw new ApiException('Live playback is not enabled', 404);
        }

        return $this->streams;
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @return array<string, mixed>
     */
    private function listLogs(): array
    {
        if ($this->logs === null) {
            throw new ApiException('Logs are not available', 503);
        }

        return ['logs' => $this->logs->sources()];
    }

    /**
     * @return array<string, mixed>
     */
    private function readLog(string $id, int $lines): array
    {
        if ($this->logs === null) {
            throw new ApiException('Logs are not available', 503);
        }

        $log = $this->logs->tail($id, $lines);

        if ($log === null) {
            throw new ApiException('No such log', 404);
        }

        return $log;
    }

    private function routeRecordings(string $method, string $path, Request $request): array
    {
        $store = $this->recordingStore();

        if ($path === '/api/recordings') {
            if ($method === 'POST') {
                return $this->scheduleRecording(self::jsonBody($request));
            }

            self::requireMethod($method, 'GET');

            $device = (string) $request->query->get('device', '');
            $device = $device === '' ? null : self::validateHost($device);

            $free = $this->recorder === null ? false : @disk_free_space($this->recorder->getDirectory());

            return [
                'directory' => $this->recorder === null ? null : $this->recorder->getDirectory(),
                // Recordings are large and the drive they live on may not even be attached.
                'freeBytes'     => $free === false ? null : (int) $free,
                'formats'       => RecordingStore::FORMATS,
                'defaultFormat' => self::environmentValue('RECORDING_FORMAT', 'ts'),
                'schedules'     => $store->getSchedules($device),
                'recordings'    => self::withArtwork(self::withHdFlags($store->getRecordings($device), $this->guide)),
                // Standing rules ride along with the list the page already polls.
                'rules' => $store->getRules($device),
            ];
        }

        if ($path === '/api/recordings/rules') {
            if ($method === 'POST') {
                return $this->addSeriesRule(self::jsonBody($request));
            }

            self::requireMethod($method, 'GET');
            $device = (string) $request->query->get('device', '');

            return ['rules' => $store->getRules($device === '' ? null : self::validateHost($device))];
        }

        if (preg_match('#^/api/recordings/rules/(\d+)$#', $path, $match)) {
            self::requireMethod($method, 'DELETE');

            return ['cancelled' => $store->deleteRule((int) $match[1])];
        }

        if (preg_match('#^/api/recordings/schedules/(\d+)$#', $path, $match)) {
            self::requireMethod($method, 'DELETE');

            return $this->cancelSchedule((int) $match[1]);
        }

        if (preg_match('#^/api/recordings/(\d+)/stop$#', $path, $match)) {
            self::requireMethod($method, 'POST');

            return $this->stopRecording((int) $match[1]);
        }

        if (preg_match('#^/api/recordings/(\d+)/convert$#', $path, $match)) {
            self::requireMethod($method, 'POST');

            return $this->convertRecording((int) $match[1], self::jsonBody($request));
        }

        if (preg_match('#^/api/recordings/(\d+)/play$#', $path, $match)) {
            return $this->routePlayback($method, (int) $match[1], $request);
        }

        if (!preg_match('#^/api/recordings/(\d+)$#', $path, $match)) {
            throw new ApiException('Not found', 404);
        }

        self::requireMethod($method, 'DELETE');

        return $this->deleteRecording((int) $match[1]);
    }

    /**
     * Mark what actually has a picture, so the page never asks for one that does not exist.
     *
     * @param list<array<string, mixed>> $channels
     * @return list<array<string, mixed>>
     */
    private static function withPictures(array $channels): array
    {
        $logos   = ChannelLogos::fromEnvironment();
        $artwork = ProgrammeArtwork::fromEnvironment();

        return array_map(static function (array $channel) use ($logos, $artwork): array {
            $channel['logo'] = $logos->pathFor((string) $channel['virtual']) !== null;

            // An ATSC 3.0 service simulcasts the channel a hundred below it, and the
            // station's logo is filed under that number. When the 3.0 number has none of
            // its own, the counterpart is where to look, and the page is told which number
            // to ask for so it does not request one that was never fetched.
            if ($channel['logo'] === false && ($channel['atsc3'] ?? false)) {
                $counterpart = GuideStore::atsc3Counterpart((string) $channel['virtual']);

                if ($counterpart !== null && $logos->pathFor($counterpart) !== null) {
                    $channel['logo']    = true;
                    $channel['logoFor'] = $counterpart;
                }
            }

            $channel['events'] = array_map(static function (array $event) use ($artwork): array {
                $event['art'] = $artwork->pathFor((string) ($event['title'] ?? '')) !== null;

                return $event;
            }, $channel['events'] ?? []);

            return $channel;
        }, $channels);
    }

    /**
     * Mark the recordings that have a picture, on the same terms as the guide.
     *
     * @param list<array<string, mixed>> $recordings
     * @return list<array<string, mixed>>
     */
    private static function withArtwork(array $recordings): array
    {
        if ($recordings === []) {
            return $recordings;
        }

        $artwork = ProgrammeArtwork::fromEnvironment();

        return array_map(static function (array $recording) use ($artwork): array {
            $recording['art'] = $artwork->pathFor((string) ($recording['title'] ?? '')) !== null;

            return $recording;
        }, $recordings);
    }

    /**
     * Say which recordings came from a channel the guide knows to be high definition.
     *
     * A recording keeps the channel it came from but not what that channel was, and the
     * lineup is where that is written down.
     *
     * @param list<array<string, mixed>> $recordings
     * @return list<array<string, mixed>>
     */
    private static function withHdFlags(array $recordings, ?GuideStore $guide): array
    {
        if ($guide === null || $recordings === []) {
            return $recordings;
        }

        $hd = [];

        foreach ($guide->getLineup() as $channel) {
            $hd[$channel['device'] . ' ' . $channel['virtual']] = (bool) ($channel['hd'] ?? false);
        }

        return array_map(static function (array $recording) use ($hd): array {
            $recording['hd'] = $hd[$recording['device'] . ' ' . $recording['virtual']] ?? false;

            return $recording;
        }, $recordings);
    }

    /**
     * Ask for a recording kept as broadcast to be converted for browsers.
     *
     * The recorder picks it up on its next pass; nothing is deleted, so the broadcast stays
     * until it is removed by hand. Without a height the picture is left as it was sent.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function convertRecording(int $id, array $body): array
    {
        $store     = $this->recordingStore();
        $recording = $store->getRecording($id);

        if ($recording === null) {
            throw new ApiException('No such recording', 404);
        }

        if ($recording['status'] !== RecordingStore::STATUS_DONE) {
            throw new ApiException('That recording has not finished yet', 409);
        }

        if ($recording['format'] === 'mp4' || $recording['convertedPath'] !== null) {
            throw new ApiException('That recording already plays in a browser', 409);
        }

        // Already under way: asking again is not an error, it just says so.
        if ($recording['convertPid'] !== null) {
            return ['queued' => true, 'recording' => $recording];
        }

        $height = $body['height'] ?? null;

        if ($height !== null && (!is_int($height) || $height < 144 || $height > 2160)) {
            throw new ApiException('Expected "height" to be between 144 and 2160, or nothing to keep the broadcast\'s', 400);
        }

        // A conversion that failed before is excluded by its error, so clear it or asking
        // again would quietly do nothing.
        $store->updateRecording($id, [
            'convertRequested' => 1,
            'convertHeight'    => $height,
            'convertError'     => null,
        ]);

        return ['queued' => true, 'recording' => $store->getRecording($id)];
    }

    /**
     * Record every showing of a title on one channel.
     *
     * The rule is evaluated straight away, so whatever is already in the guide is scheduled
     * now rather than whenever the guide next refreshes.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function addSeriesRule(array $body): array
    {
        $store = $this->recordingStore();
        $title = trim(is_string($body['title'] ?? null) ? $body['title'] : '');

        if ($title === '') {
            throw new ApiException('Expected {"title": "<program>"}', 400);
        }

        $format = is_string($body['format'] ?? null) ? $body['format'] : self::environmentValue('RECORDING_FORMAT', 'ts');

        if (!in_array($format, RecordingStore::FORMATS, true)) {
            throw new ApiException('Expected "format" to be one of: ' . implode(', ', RecordingStore::FORMATS), 400);
        }

        $rule = [
            'device'      => self::validateHost(is_string($body['device'] ?? null) ? $body['device'] : ''),
            'physical'    => self::positiveInteger($body['physical'] ?? null, 'physical'),
            'program'     => self::positiveInteger($body['program'] ?? null, 'program'),
            'virtual'     => is_string($body['virtual'] ?? null) ? $body['virtual'] : '',
            'channelName' => is_string($body['channelName'] ?? null) ? $body['channelName'] : '',
            'title'       => $title,
            'earliest'    => self::minuteOfDay($body['earliest'] ?? null),
            'latest'      => self::minuteOfDay($body['latest'] ?? null),
            'days'        => self::weekdays($body['days'] ?? null),
            'timezone'    => is_string($body['timezone'] ?? null) && $body['timezone'] !== '' ? $body['timezone'] : 'UTC',
            'format'      => $format,
            'padStart'    => self::padding($body['padStart'] ?? null, 'RECORDING_PAD_START', 60),
            'padEnd'      => self::padding($body['padEnd'] ?? null, 'RECORDING_PAD_END', 180),
        ];

        $id = $store->addRule($rule);

        // Only this device: a rule for one tuner has no business sweeping the others.
        $scheduled = $this->series === null ? 0 : $this->series->evaluate($rule['device'])['scheduled'];

        return [
            'rule'      => $store->findRule($rule['device'], $rule['physical'], $rule['program'], $rule['title']),
            'id'        => $id,
            'scheduled' => $scheduled,
        ];
    }

    /**
     * Minutes since midnight, or null for "any time".
     *
     * @param mixed $value
     */
    private static function minuteOfDay($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_int($value) || $value < 0 || $value > 1439) {
            throw new ApiException('Expected a minute of the day between 0 and 1439', 400);
        }

        return $value;
    }

    /**
     * ISO weekday numbers as "1,2,3", or null for every day.
     *
     * @param mixed $value
     */
    private static function weekdays($value): ?string
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        $days = is_array($value) ? $value : explode(',', (string) $value);
        $days = array_map('intval', array_map('trim', array_map('strval', $days)));

        foreach ($days as $day) {
            if ($day < 1 || $day > 7) {
                throw new ApiException('Expected weekdays between 1 (Monday) and 7 (Sunday)', 400);
            }
        }

        return implode(',', array_unique($days));
    }

    /**
     * Record one showing of a program from the guide.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function scheduleRecording(array $body): array
    {
        $store    = $this->recordingStore();
        $device   = self::validateHost(is_string($body['device'] ?? null) ? $body['device'] : '');
        $title    = trim(is_string($body['title'] ?? null) ? $body['title'] : '');
        $start    = self::positiveInteger($body['start'] ?? null, 'start');
        $duration = self::positiveInteger($body['duration'] ?? null, 'duration');

        if ($title === '') {
            throw new ApiException('Expected {"title": "<program>"}', 400);
        }

        if ($start + $duration <= time()) {
            throw new ApiException('That program has already ended', 400);
        }

        $format = is_string($body['format'] ?? null) ? $body['format'] : self::environmentValue('RECORDING_FORMAT', 'ts');

        if (!in_array($format, RecordingStore::FORMATS, true)) {
            throw new ApiException('Expected "format" to be one of: ' . implode(', ', RecordingStore::FORMATS), 400);
        }

        $id = $store->addSchedule([
            'device'      => $device,
            'physical'    => self::positiveInteger($body['physical'] ?? null, 'physical'),
            'program'     => self::positiveInteger($body['program'] ?? null, 'program'),
            'virtual'     => is_string($body['virtual'] ?? null) ? $body['virtual'] : '',
            'channelName' => is_string($body['channelName'] ?? null) ? $body['channelName'] : '',
            'eventId'     => isset($body['eventId']) && is_int($body['eventId']) ? $body['eventId'] : null,
            'start'       => $start,
            'duration'    => $duration,
            'title'       => $title,
            'description' => is_string($body['description'] ?? null) ? $body['description'] : null,
            'padStart'    => self::padding($body['padStart'] ?? null, 'RECORDING_PAD_START', 60),
            'padEnd'      => self::padding($body['padEnd'] ?? null, 'RECORDING_PAD_END', 180),
            'format'      => $format,
        ]);

        return ['scheduled' => true, 'id' => $id, 'schedule' => $store->getSchedule($id)];
    }

    /**
     * @return array<string, mixed>
     */
    private function cancelSchedule(int $id): array
    {
        $store    = $this->recordingStore();
        $schedule = $store->getSchedule($id);

        if ($schedule === null) {
            throw new ApiException("No schedule with id $id", 404);
        }

        foreach ($store->getRecordings(null, RecordingStore::STATUS_RECORDING) as $recording) {
            if ($recording['scheduleId'] === $id) {
                $store->requestStop($recording['id'], RecordingStore::STATUS_CANCELLED);
            }
        }

        // The schedule is forgotten either way; a recording it already made keeps its own
        // row, so cancelling never leaves something in the list that cannot be cleared.
        $store->deleteSchedule($id);

        return ['cancelled' => true, 'id' => $id];
    }

    /**
     * @return array<string, mixed>
     */
    private function stopRecording(int $id): array
    {
        $store = $this->recordingStore();

        if (!$store->requestStop($id, RecordingStore::STATUS_DONE)) {
            throw new ApiException("Recording $id is not running", 409);
        }

        // The recorder owns the ffmpeg process and stops it on its next pass.
        return ['stopping' => true, 'id' => $id];
    }

    /**
     * @return array<string, mixed>
     */
    private function deleteRecording(int $id): array
    {
        $store     = $this->recordingStore();
        $recording = $store->getRecording($id);

        if ($recording === null) {
            throw new ApiException("No recording with id $id", 404);
        }

        if ($recording['status'] === RecordingStore::STATUS_RECORDING) {
            throw new ApiException('That recording is still running; stop it first', 409);
        }

        // The recorder owns that conversion and only it can stop it; converting is quick.
        if (($recording['convertPid'] ?? null) !== null) {
            throw new ApiException('That recording is still being copied for browsers; try again in a moment', 409);
        }

        // Whatever was converted for watching it goes too.
        $this->playback?->forget($id);

        foreach ($this->recorder === null ? [] : $this->recorder->filesFor($recording) as $file) {
            @unlink($file);
        }

        $store->deleteRecording($id);

        return ['deleted' => true, 'id' => $id];
    }

    /**
     * @return array<string, mixed>
     */
    private function routePlayback(string $method, int $id, Request $request): array
    {
        $playback = $this->recordingPlayback();

        // Playback reports its own trouble (a missing file, a recording still running) as
        // runtime errors; they are the caller's problem, not a server fault.
        try {
            if ($method === 'POST') {
                return $playback->play($id, self::validateViewer(self::jsonBody($request)['viewer'] ?? null));
            }

            $viewer = $request->query->get('viewer');

            if ($method === 'DELETE') {
                return $playback->leave($id, self::validateViewer($viewer));
            }

            self::requireMethod($method, 'GET');

            return $playback->status($id, $viewer === null ? null : self::validateViewer($viewer));
        } catch (RuntimeException $e) {
            throw new ApiException($e->getMessage(), 409);
        }
    }

    private function recordingPlayback(): RecordingPlayback
    {
        if ($this->playback === null) {
            throw new ApiException('Recording is not enabled', 404);
        }

        return $this->playback;
    }

    /**
     * A tuner a recording is using must not be retuned or streamed from underneath it.
     */
    private function guardTunerFree(string $host, int $tuner): void
    {
        $held = $this->reservations === null ? null : $this->reservations->find($host, $tuner);

        if ($held === null) {
            return;
        }

        throw new ApiException("Tuner $tuner is busy: {$held['label']}", 409);
    }

    private function recordingStore(): RecordingStore
    {
        if ($this->recordings === null) {
            throw new ApiException('Recording is not enabled', 404);
        }

        return $this->recordings;
    }

    /**
     * @param mixed $value
     */
    private static function positiveInteger($value, string $name): int
    {
        if (!is_int($value) || $value < 1) {
            throw new ApiException("Expected \"$name\" to be a positive whole number", 400);
        }

        return $value;
    }

    /**
     * Extra seconds before or after a program, for broadcasts that do not keep to time.
     *
     * @param mixed $value
     */
    private static function padding($value, string $variable, int $fallback): int
    {
        $seconds = is_int($value) ? $value : (int) self::environmentValue($variable, (string) $fallback);

        return max(0, min(1800, $seconds));
    }

    private static function environmentValue(string $name, string $default): string
    {
        $value = getenv($name);

        return $value === false || $value === '' ? $default : $value;
    }

    /**
     * @param mixed $viewer
     */
    private static function validateViewer($viewer): string
    {
        if (!is_string($viewer) || !preg_match('/^[A-Za-z0-9-]{8,64}$/', $viewer)) {
            throw new ApiException('Expected a viewer id of 8 to 64 letters, digits or dashes', 400);
        }

        return $viewer;
    }

    private static function physicalChannel(string $channel): ?int
    {
        return preg_match('/^[a-z0-9]+:(\d+)/', $channel, $match) ? (int) $match[1] : null;
    }

    private static function validateHost(string $host): string
    {
        $isIpv4   = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        $isPublic = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;

        if (!$isIpv4 || $isPublic) {
            throw new ApiException("Devices must be addressed by a private or loopback IPv4 address: $host", 400);
        }

        return $host;
    }

    private static function requireMethod(string $actual, string $expected): void
    {
        if ($actual !== $expected) {
            throw new ApiException("Method $actual not allowed, use $expected", 405);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function jsonBody(Request $request): array
    {
        $body = json_decode((string) $request->getContent(), true);

        if (!is_array($body)) {
            throw new ApiException('Expected a JSON object in the request body', 400);
        }

        return $body;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function json(array $data, int $status = 200): JsonResponse
    {
        // Channel and program names come from broadcasts and are not always valid UTF-8.
        $response = new JsonResponse(null, $status);
        $response->setEncodingOptions(JsonResponse::DEFAULT_ENCODING_OPTIONS | JSON_INVALID_UTF8_SUBSTITUTE);
        $response->setData($data);

        return $response;
    }
}
