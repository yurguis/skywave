<?php declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave\Ac3;

/**
 * Accumulates TS-packet payload bytes for one AC-3 PID and, as soon as a
 * full sync-frame header is buffered, decodes it. Stops buffering once a
 * frame is captured — we only need one to describe the service.
 *
 * Callers must feed payload bytes already stripped of TS header and
 * adaptation field. The first payload-unit-start packet on the PID carries
 * a PES header that must be peeled before the AC-3 stream begins.
 */
class SyncFrameSampler
{
    private const PES_START_CODE = "\x00\x00\x01";
    /** Bytes we need after the sync word to decode the header. */
    private const SYNC_HEADER_BYTES = 10;
    /** Cap the rolling buffer so a payload with no sync word can't grow unbounded. */
    private const MAX_BUFFER_BYTES = 4096;

    private string $buffer = '';
    private ?SyncFrame $frame = null;

    public function feed(string $payload, bool $payloadUnitStart): void
    {
        if ($this->frame !== null) {
            return;
        }

        if ($payloadUnitStart) {
            $this->buffer = $this->stripPesHeader($payload);
        } else {
            $this->buffer .= $payload;
        }

        $this->scan();
        $this->trimBuffer();
    }

    public function getFrame(): ?SyncFrame
    {
        return $this->frame;
    }

    public function isComplete(): bool
    {
        return $this->frame !== null;
    }

    private function scan(): void
    {
        $offset = 0;

        while (($pos = strpos($this->buffer, SyncFrame::SYNC_WORD, $offset)) !== false) {
            if (strlen($this->buffer) - $pos < self::SYNC_HEADER_BYTES) {
                // Need more bytes before we can decode this candidate.
                $this->buffer = substr($this->buffer, $pos);

                return;
            }

            $frame = SyncFrame::decode(substr($this->buffer, $pos, self::SYNC_HEADER_BYTES));

            if ($frame !== null) {
                $this->frame  = $frame;
                $this->buffer = '';

                return;
            }

            // 0x0B77 was a false positive; keep scanning past it.
            $offset = $pos + 2;
        }
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

        // Keep the tail in case a sync word straddles the trim boundary.
        $this->buffer = substr($this->buffer, -self::MAX_BUFFER_BYTES);
    }
}
