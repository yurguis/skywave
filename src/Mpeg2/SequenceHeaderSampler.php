<?php declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave\Mpeg2;

/**
 * Accumulates TS-packet payload for one MPEG-2 video PID and emits the
 * first decoded sequence header. Stops buffering once a header is captured.
 */
class SequenceHeaderSampler
{
    private const PES_START_CODE = "\x00\x00\x01";
    /** Sequence header + extension fit in well under 1 KB; cap the rolling buffer to bound memory. */
    private const MAX_BUFFER_BYTES = 16384;

    private string $buffer = '';
    private ?SequenceHeader $header = null;

    public function feed(string $payload, bool $payloadUnitStart): void
    {
        if ($this->header !== null) {
            return;
        }

        if ($payloadUnitStart) {
            $this->buffer = $this->stripPesHeader($payload);
        } else {
            $this->buffer .= $payload;
        }

        $this->header = SequenceHeader::decode($this->buffer);

        if ($this->header !== null) {
            $this->buffer = '';

            return;
        }

        $this->trimBuffer();
    }

    public function getHeader(): ?SequenceHeader
    {
        return $this->header;
    }

    public function isComplete(): bool
    {
        return $this->header !== null;
    }

    private function stripPesHeader(string $payload): string
    {
        if (strlen($payload) < 9 || substr($payload, 0, 3) !== self::PES_START_CODE) {
            return '';
        }

        $pesHeaderLen = ord($payload[8]);
        $start        = 9 + $pesHeaderLen;

        if ($start > strlen($payload)) {
            return '';
        }

        return substr($payload, $start);
    }

    private function trimBuffer(): void
    {
        if (strlen($this->buffer) <= self::MAX_BUFFER_BYTES) {
            return;
        }

        // Preserve the tail so a start code straddling the trim boundary survives.
        $this->buffer = substr($this->buffer, -self::MAX_BUFFER_BYTES);
    }
}
