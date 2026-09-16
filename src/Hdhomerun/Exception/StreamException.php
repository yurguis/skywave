<?php declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave\Hdhomerun\Exception;

/**
 * An HTTP stream from the device could not be read.
 */
class StreamException extends HdhomerunException
{
    private bool $refused;

    public function __construct(string $message, bool $refused = false)
    {
        parent::__construct($message);

        $this->refused = $refused;
    }

    /**
     * True when the device answered but would not stream (tuner in use, no signal, ...)
     * or sent something other than MPEG-TS; false when it could not be reached.
     */
    public function isRefused(): bool
    {
        return $this->refused;
    }
}
