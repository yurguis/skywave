<?php declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave\Hdhomerun;

/**
 * Parsed /tunerN/vstatus (virtual channel and CableCARD authorization state),
 * mirroring libhdhomerun's hdhomerun_tuner_vstatus_t.
 */
class VirtualStatus
{
    private string $raw;
    private string $virtualChannel;
    private string $name;
    private string $auth;
    private string $cci;
    private string $cgms;

    public function __construct(string $raw)
    {
        $this->raw            = $raw;
        $this->virtualChannel = StatusString::value($raw, 'vch') ?? '';
        $this->name           = StatusString::value($raw, 'name') ?? '';
        $this->auth           = StatusString::value($raw, 'auth') ?? '';
        $this->cci            = StatusString::value($raw, 'cci') ?? '';
        $this->cgms           = StatusString::value($raw, 'cgms') ?? '';
    }

    public function getRaw(): string
    {
        return $this->raw;
    }

    public function getVirtualChannel(): string
    {
        return $this->virtualChannel;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getAuth(): string
    {
        return $this->auth;
    }

    public function getCci(): string
    {
        return $this->cci;
    }

    public function getCgms(): string
    {
        return $this->cgms;
    }

    public function isNotSubscribed(): bool
    {
        return strncmp($this->auth, 'not-subscribed', 14) === 0;
    }

    public function isNotAvailable(): bool
    {
        return strncmp($this->auth, 'error', 5) === 0 || strncmp($this->auth, 'dialog', 6) === 0;
    }

    public function isCopyProtected(): bool
    {
        return strncmp($this->cci, 'protected', 9) === 0 || strncmp($this->cgms, 'protected', 9) === 0;
    }
}
