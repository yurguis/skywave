<?php

declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave\Radio;

use RuntimeException;

/**
 * Asks an rtl_tcp server what dongle it has.
 *
 * That is all it asks. rtl_tcp serves one client at a time and gives the next one nothing
 * until the first has gone, so this connects, reads the twelve bytes every connection opens
 * with, and leaves. While nrsc5 is listening there is no answer to be had: the connection is
 * accepted by the kernel and then sits unanswered, which is reported as the dongle being in
 * use rather than as a fault.
 */
final class RtlTcpClient
{
    private string $host;
    private int $port;
    private float $timeout;

    public function __construct(string $host, int $port = RtlTcp::PORT, float $timeout = 1.5)
    {
        $this->host    = $host;
        $this->port    = $port;
        $this->timeout = $timeout;
    }

    /**
     * @return array{tuner: string, tunerType: int, gainCount: int}
     */
    public function probe(): array
    {
        $socket = @stream_socket_client("tcp://$this->host:$this->port", $errno, $error, $this->timeout);

        if ($socket === false) {
            throw new RuntimeException("No rtl_tcp server at $this->host:$this->port ($error)");
        }

        stream_set_timeout($socket, (int) $this->timeout, (int) (fmod($this->timeout, 1) * 1000000));

        $header = '';

        while (strlen($header) < RtlTcp::HEADER_SIZE) {
            $chunk = fread($socket, RtlTcp::HEADER_SIZE - strlen($header));

            if ($chunk === false || $chunk === '') {
                break;
            }

            $header .= $chunk;
        }

        fclose($socket);

        if ($header === '') {
            throw new RuntimeException("The dongle at $this->host:$this->port is in use: rtl_tcp serves one listener at a time");
        }

        $info = RtlTcp::decodeHeader($header);

        return [
            'tuner'     => RtlTcp::tunerName($info['tunerType']),
            'tunerType' => $info['tunerType'],
            'gainCount' => $info['gainCount'],
        ];
    }
}
