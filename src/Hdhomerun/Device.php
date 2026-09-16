<?php declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave\Hdhomerun;

use Skywave\Hdhomerun\Exception\DeviceErrorException;

/**
 * An HDHomeRun device: system variables plus access to its tuners.
 *
 *   foreach ((new Discovery())->findDevices() as $found) {
 *       $device = Device::fromDiscovery($found);
 *       echo $device->getModel(), ': ', $device->getTuner(0)->getStatus()->getLock(), "\n";
 *   }
 */
class Device
{
    /** Upper bound when probing for tuners, same as hdhomerun_tui. */
    private const MAX_PROBED_TUNERS = 8;

    private ControlClient $control;
    private ?DiscoveredDevice $discovered;
    private ?int $tunerCount = null;

    public function __construct(ControlClient $control, ?DiscoveredDevice $discovered = null)
    {
        $this->control    = $control;
        $this->discovered = $discovered;
    }

    public static function fromDiscovery(DiscoveredDevice $discovered, float $timeout = 2.5): self
    {
        return new self(new ControlClient($discovered->getIp(), Packet::PORT, $timeout), $discovered);
    }

    /**
     * Connect to a device by IP address or host name. For an IPv4 address a targeted
     * discovery request fills in the device ID and tuner count; the control connection
     * works without it, e.g. when UDP is filtered between here and the device.
     */
    public static function at(string $host, ?Discovery $discovery = null, float $timeout = 2.5): self
    {
        $discovered = null;

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $discovered = ($discovery ?? new Discovery())->findDeviceAt($host);
        }

        return new self(new ControlClient($host, Packet::PORT, $timeout), $discovered);
    }

    public function getControl(): ControlClient
    {
        return $this->control;
    }

    public function getHost(): string
    {
        return $this->control->getHost();
    }

    /**
     * Discovery reply details (device ID, auth token, URLs); null if the device
     * was not discovered.
     */
    public function getDiscovered(): ?DiscoveredDevice
    {
        return $this->discovered;
    }

    public function getDeviceId(): ?int
    {
        return $this->discovered === null ? null : $this->discovered->getDeviceId();
    }

    /**
     * Firmware model name, e.g. "hdhomerun5_atsc".
     */
    public function getModel(): string
    {
        // The very first ATSC models predate /sys/model.
        return $this->control->tryGet('/sys/model') ?? 'hdhomerun_atsc';
    }

    /**
     * Hardware model as printed on the box, e.g. "HDHR5-4US"; null on older firmware.
     */
    public function getHardwareModel(): ?string
    {
        return $this->control->tryGet('/sys/hwmodel');
    }

    public function getFirmwareVersion(): string
    {
        return $this->control->get('/sys/version');
    }

    /**
     * /sys/features as a map of feature to supported values, e.g.
     * ['channelmap' => ['us-bcast', 'us-cable'], 'modulation' => ['8vsb', 'qam256']].
     *
     * @return array<string, string[]>
     */
    public function getFeatures(): array
    {
        $features = [];

        foreach (preg_split('/\r?\n/', $this->control->get('/sys/features')) as $line) {
            if (preg_match('/^\s*([^:\s]+):\s*(.*)$/', $line, $match)) {
                $features[$match[1]] = preg_split('/\s+/', trim($match[2]), -1, PREG_SPLIT_NO_EMPTY);
            }
        }

        return $features;
    }

    /**
     * @return string[] channel map names this device accepts, e.g. ["us-bcast", "us-cable"]
     */
    public function getChannelMaps(): array
    {
        return $this->getFeatures()['channelmap'] ?? [];
    }

    /**
     * Number of tuners, from discovery when available, otherwise by probing
     * /tunerN/status until the device rejects the index.
     */
    public function getTunerCount(): int
    {
        if ($this->tunerCount !== null) {
            return $this->tunerCount;
        }

        if ($this->discovered !== null && $this->discovered->getTunerCount() > 0) {
            return $this->tunerCount = $this->discovered->getTunerCount();
        }

        $count = 0;

        while ($count < self::MAX_PROBED_TUNERS) {
            try {
                $this->control->get("/tuner$count/status");
            } catch (DeviceErrorException $e) {
                break;
            }

            $count++;
        }

        return $this->tunerCount = $count;
    }

    public function getTuner(int $index): Tuner
    {
        return new Tuner($this->control, $index);
    }

    /**
     * @return Tuner[]
     */
    public function getTuners(): array
    {
        $tuners = [];

        for ($index = 0; $index < $this->getTunerCount(); $index++) {
            $tuners[] = $this->getTuner($index);
        }

        return $tuners;
    }
}
