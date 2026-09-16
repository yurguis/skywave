<?php declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave\Hdhomerun;

use InvalidArgumentException;
use Skywave\Hdhomerun\Exception\DeviceErrorException;

/**
 * One tuner of a device, mirroring the per-tuner calls of libhdhomerun's
 * hdhomerun_device.c.
 *
 * Tuners are shared between every client on the network. requestLock() reserves
 * one: while held, the lock key is sent with every change and other clients' changes
 * are rejected by the device.
 */
class Tuner
{
    private ControlClient $control;
    private int $index;
    private int $lockkey = 0;

    public function __construct(ControlClient $control, int $index)
    {
        if ($index < 0) {
            throw new InvalidArgumentException("Tuner index cannot be negative: $index");
        }

        $this->control = $control;
        $this->index   = $index;
    }

    public function getIndex(): int
    {
        return $this->index;
    }

    public function getStatus(): TunerStatus
    {
        return new TunerStatus($this->control->get($this->path('status')));
    }

    /**
     * Virtual channel status; null on models without virtual channel support.
     */
    public function getVirtualStatus(): ?VirtualStatus
    {
        $vstatus = $this->control->tryGet($this->path('vstatus'));

        return $vstatus === null ? null : new VirtualStatus($vstatus);
    }

    public function getStreamInfo(): StreamInfo
    {
        return new StreamInfo($this->control->get($this->path('streaminfo')));
    }

    /**
     * ATSC 3.0 physical layer pipe summary; null when not supported or not tuned to ATSC 3.0.
     */
    public function getPlpInfo(): ?string
    {
        return $this->control->tryGet($this->path('plpinfo'));
    }

    /**
     * ATSC 3.0 L1 signaling detail; null when not supported or not tuned to ATSC 3.0.
     */
    public function getL1Detail(): ?string
    {
        return $this->control->tryGet($this->path('l1detail'));
    }

    public function getDebug(): ?string
    {
        return $this->control->tryGet($this->path('debug'));
    }

    public function getChannel(): string
    {
        return $this->control->get($this->path('channel'));
    }

    /**
     * Tune to a physical channel, e.g. "auto:33", "8vsb:33", "atsc3:33", or "none".
     */
    public function setChannel(string $channel): void
    {
        $this->set('channel', $channel);
    }

    public function getVirtualChannel(): ?string
    {
        return $this->control->tryGet($this->path('vchannel'));
    }

    public function setVirtualChannel(string $virtualChannel): void
    {
        $this->set('vchannel', $virtualChannel);
    }

    public function getChannelMap(): string
    {
        return $this->control->get($this->path('channelmap'));
    }

    /**
     * Select a channel map, e.g. "us-bcast". Device::getChannelMaps() lists them.
     */
    public function setChannelMap(string $channelMap): void
    {
        $this->set('channelmap', $channelMap);
    }

    public function getProgram(): string
    {
        return $this->control->get($this->path('program'));
    }

    /**
     * Restrict the output to one MPEG program number (see StreamProgram), or "0" for all.
     */
    public function setProgram(string $program): void
    {
        $this->set('program', $program);
    }

    public function getFilter(): string
    {
        return $this->control->get($this->path('filter'));
    }

    /**
     * PID filter, e.g. "0x0000-0x1FFF".
     */
    public function setFilter(string $filter): void
    {
        $this->set('filter', $filter);
    }

    public function getTarget(): string
    {
        return $this->control->get($this->path('target'));
    }

    /**
     * Send the stream somewhere, e.g. "rtp://192.168.1.10:5000".
     */
    public function setTarget(string $target): void
    {
        $this->set('target', $target);
    }

    /**
     * Point the stream at this machine, on the interface that reaches the device.
     *
     * @return string the target that was set
     */
    public function setTargetToLocal(int $port, string $protocol = 'rtp'): string
    {
        if (!in_array($protocol, ['rtp', 'udp'], true)) {
            throw new InvalidArgumentException("Unsupported stream protocol: $protocol");
        }

        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException("Port out of range: $port");
        }

        $target = sprintf('%s://%s:%d', $protocol, $this->control->getLocalAddress(), $port);
        $this->setTarget($target);

        return $target;
    }

    public function clearTarget(): void
    {
        $this->setTarget('none');
    }

    /**
     * Who holds the tuner lock ("none" or the owner's IP); null if unsupported.
     */
    public function getLockOwner(): ?string
    {
        return $this->control->tryGet($this->path('lockkey'));
    }

    /**
     * Reserve the tuner for this client.
     *
     * @throws DeviceErrorException when another client holds the lock
     */
    public function requestLock(): void
    {
        $lockkey = random_int(1, 0xFFFFFFFF);

        try {
            $this->control->set($this->path('lockkey'), (string) $lockkey, $this->lockkey);
        } catch (DeviceErrorException $e) {
            $this->lockkey = 0;

            throw $e;
        }

        $this->lockkey = $lockkey;
    }

    public function releaseLock(): void
    {
        if ($this->lockkey === 0) {
            return;
        }

        try {
            $this->control->set($this->path('lockkey'), 'none', $this->lockkey);
        } finally {
            $this->lockkey = 0;
        }
    }

    /**
     * Break another client's lock.
     */
    public function forceLock(): void
    {
        try {
            $this->control->set($this->path('lockkey'), 'force');
        } finally {
            $this->lockkey = 0;
        }
    }

    /**
     * Lock key currently held by this client, 0 when none.
     */
    public function getLockkey(): int
    {
        return $this->lockkey;
    }

    /**
     * Adopt a lock key obtained earlier, e.g. by a previous request in a web app.
     */
    public function useLockkey(int $lockkey): void
    {
        $this->lockkey = $lockkey;
    }

    /**
     * After tuning, wait until the demodulator locks, no signal is detected, or
     * $timeout seconds pass. Quality readings are not settled yet at that point.
     */
    public function waitForLock(float $timeout = 2.5): TunerStatus
    {
        // Signal strength needs a moment after tuning before it means anything.
        usleep(250000);

        $deadline = microtime(true) + $timeout;

        while (true) {
            $status = $this->getStatus();

            if (!$status->isSignalPresent()
                || $status->isLockSupported()
                || $status->isLockUnsupported()
                || microtime(true) >= $deadline) {
                return $status;
            }

            usleep(250000);
        }
    }

    private function set(string $variable, string $value): void
    {
        $this->control->set($this->path($variable), $value, $this->lockkey);
    }

    private function path(string $variable): string
    {
        return "/tuner$this->index/$variable";
    }
}
