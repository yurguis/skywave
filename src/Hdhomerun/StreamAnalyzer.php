<?php

declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave\Hdhomerun;

use Skywave\Hdhomerun\Exception\StreamException;
use Skywave\Parser;

/**
 * Reads a device's HTTP stream (port 5004) through the analyzer until its tables are
 * complete or time runs out. Requesting a tuner's stream tunes that tuner.
 */
class StreamAnalyzer
{
    public const HTTP_PORT = 5004;

    private const PACKET_SIZE = 188;

    /**
     * URL of a tuner's full multiplex on a physical channel.
     */
    public static function tunerUrl(string $host, int $tuner, int $physicalChannel, ?int $duration = null): string
    {
        return sprintf('http://%s:%d/tuner%d/ch%d', $host, self::HTTP_PORT, $tuner, $physicalChannel)
            . ($duration === null ? '' : "?duration=$duration");
    }

    /**
     * @return array{parser: Parser, complete: bool, bytes: int, seconds: float}
     * @throws StreamException
     */
    public function analyze(string $url, int $seconds): array
    {
        $context = stream_context_create(['http' => ['timeout' => 5.0, 'ignore_errors' => true]]);
        $handle  = @fopen($url, 'rb', false, $context);

        if ($handle === false) {
            throw new StreamException("Unable to open $url: " . (error_get_last()['message'] ?? 'unknown error'));
        }

        $started = microtime(true);
        $parser  = new Parser();
        $bytes   = 0;
        $done    = false;

        try {
            self::assertOpened($handle);

            $deadline = $started + $seconds;
            $buffer   = '';

            while (!$done && !feof($handle) && microtime(true) < $deadline) {
                $chunk = fread($handle, self::PACKET_SIZE * 512);

                if ($chunk === false || ($chunk === '' && stream_get_meta_data($handle)['timed_out'])) {
                    break;
                }

                if ($bytes === 0 && $chunk !== '' && $chunk[0] !== "\x47") {
                    throw new StreamException('The device did not send an MPEG-TS stream (ATSC 3.0 is not supported here yet)', true);
                }

                $bytes += strlen($chunk);
                $buffer .= $chunk;
                $offset = 0;

                for (; $offset + self::PACKET_SIZE <= strlen($buffer); $offset += self::PACKET_SIZE) {
                    if ($parser->analyze(substr($buffer, $offset, self::PACKET_SIZE)) === Parser::RETURN_TYPE_DONE) {
                        $done = true;

                        break;
                    }
                }

                $buffer = (string) substr($buffer, $offset);
            }
        } finally {
            fclose($handle);
        }

        return [
            'parser'   => $parser,
            'complete' => $done,
            'bytes'    => $bytes,
            'seconds'  => round(microtime(true) - $started, 1),
        ];
    }

    /**
     * @param resource $handle
     */
    private static function assertOpened($handle): void
    {
        $headers = stream_get_meta_data($handle)['wrapper_data'] ?? [];
        $status  = is_array($headers) && isset($headers[0]) ? (string) $headers[0] : '';

        if (preg_match('#^HTTP/\S+ 200\b#', $status)) {
            return;
        }

        $detail = $status;

        foreach ((array) $headers as $header) {
            if (stripos((string) $header, 'X-HDHomeRun-Error:') === 0) {
                $detail = trim(substr((string) $header, strlen('X-HDHomeRun-Error:')));
            }
        }

        throw new StreamException("The device refused the stream request ($detail)", true);
    }
}
