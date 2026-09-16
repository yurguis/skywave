<?php declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave\Avc;

/**
 * Accumulates TS-packet payload for one AVC video PID and emits the first
 * decoded SPS. SPS NALs repeat at IDR boundaries, so we capture the first
 * one we see and stop.
 */
class SequenceParameterSetSampler
{
    private const PES_START_CODE = "\x00\x00\x01";
    /** SPS NALs are small but may not appear in the first PES. Keep a generous window. */
    private const MAX_BUFFER_BYTES = 32768;

    private string $buffer = '';
    private ?SequenceParameterSet $sps = null;

    public function feed(string $payload, bool $payloadUnitStart): void
    {
        if ($this->sps !== null) {
            return;
        }

        if ($payloadUnitStart) {
            $this->buffer = $this->stripPesHeader($payload);
        } else {
            $this->buffer .= $payload;
        }

        $this->sps = SequenceParameterSet::decode($this->buffer);

        if ($this->sps !== null) {
            $this->buffer = '';

            return;
        }

        $this->trimBuffer();
    }

    public function getSps(): ?SequenceParameterSet
    {
        return $this->sps;
    }

    public function isComplete(): bool
    {
        return $this->sps !== null;
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

        $this->buffer = substr($this->buffer, -self::MAX_BUFFER_BYTES);
    }
}
