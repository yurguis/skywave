<?php declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave\Hdhomerun;

/**
 * Parsed /tunerN/streaminfo: the programs the tuner sees on the current channel.
 *
 * Format (one entry per line), as parsed by libhdhomerun's hdhomerun_channelscan.c:
 *   1: 7.1 KQED-HD
 *   3: 0 (control)
 *   tsid=0x0ABC
 */
class StreamInfo
{
    private string $raw;
    private ?int $transportStreamId = null;
    private ?int $originalNetworkId = null;
    /** @var StreamProgram[] */
    private array $programs = [];

    public function __construct(string $raw)
    {
        $this->raw = $raw;

        foreach (preg_split('/\r?\n/', $raw) as $line) {
            if (preg_match('/^tsid=0x([0-9a-f]+)/i', $line, $match)) {
                $this->transportStreamId = (int) hexdec($match[1]);
                continue;
            }

            if (preg_match('/^onid=0x([0-9a-f]+)/i', $line, $match)) {
                $this->originalNetworkId = (int) hexdec($match[1]);
                continue;
            }

            $program = StreamProgram::fromLine($line);

            if ($program !== null) {
                $this->programs[] = $program;
            }
        }
    }

    public function getRaw(): string
    {
        return $this->raw;
    }

    public function getTransportStreamId(): ?int
    {
        return $this->transportStreamId;
    }

    public function getOriginalNetworkId(): ?int
    {
        return $this->originalNetworkId;
    }

    /**
     * @return StreamProgram[]
     */
    public function getPrograms(): array
    {
        return $this->programs;
    }
}
