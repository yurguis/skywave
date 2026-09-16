<?php

declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave\Web;

use RuntimeException;

/**
 * An error the API reports to the client with a specific HTTP status.
 */
class ApiException extends RuntimeException
{
    private int $status;

    public function __construct(string $message, int $status)
    {
        parent::__construct($message);

        $this->status = $status;
    }

    public function getStatus(): int
    {
        return $this->status;
    }
}
