<?php

declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave\Web;

use Skywave\Hdhomerun\Exception\HdhomerunException;
use Skywave\Hdhomerun\Tuner;

/**
 * Puts a tuner back on its channel after an HTTP stream from it ends.
 *
 * Closing the stream makes the device release the tuner, but only once it notices,
 * which can be a moment later. Only a released tuner ("none") is retuned, so a channel
 * change someone made in the meantime is left alone.
 */
class TunerRelease
{
    /**
     * @param string $targetBefore the tuner's target before the stream started
     */
    public static function restoreChannel(Tuner $tuner, string $channel, string $targetBefore, float $timeout = 2.0): void
    {
        $deadline = microtime(true) + $timeout;

        try {
            while (true) {
                $current = $tuner->getStatus()->getChannel();

                if ($current === 'none') {
                    $tuner->setChannel($channel);

                    return;
                }

                if ($current !== $channel || $tuner->getTarget() === $targetBefore || microtime(true) >= $deadline) {
                    return;
                }

                usleep(100000);
            }
        } catch (HdhomerunException $e) {
            // Best effort: the stream itself has already ended.
        }
    }
}
