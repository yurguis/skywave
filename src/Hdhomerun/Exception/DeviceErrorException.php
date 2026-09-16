<?php declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave\Hdhomerun\Exception;

/**
 * The device understood the request but rejected it, e.g. an unknown variable,
 * an invalid channel, or a tuner locked by another client.
 */
class DeviceErrorException extends HdhomerunException
{
    private string $variable;
    private string $deviceMessage;

    public function __construct(string $variable, string $deviceMessage)
    {
        parent::__construct("$variable: $deviceMessage");

        $this->variable      = $variable;
        $this->deviceMessage = $deviceMessage;
    }

    public function getVariable(): string
    {
        return $this->variable;
    }

    /**
     * The error text exactly as the device sent it, e.g. "ERROR: unknown getset variable".
     */
    public function getDeviceMessage(): string
    {
        return $this->deviceMessage;
    }
}
