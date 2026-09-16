<?php declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave\Hdhomerun\Exception;

use RuntimeException;

/**
 * Base class for every error raised while talking to an HDHomeRun device.
 */
class HdhomerunException extends RuntimeException
{
}
