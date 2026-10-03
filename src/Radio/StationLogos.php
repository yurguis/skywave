<?php

declare(strict_types=1);

namespace Skywave\Radio;

use RuntimeException;

/**
 * The logos stations send, kept between listens.
 *
 * A station sends its logo on its own data port, and it arrives about a minute into a
 * listen -- long after the picture frame has already been drawn. The file itself lives in
 * the session's directory and goes when the session does, so every listen waited for it
 * again and showed a frequency on a grey square in the meantime.
 *
 * Kept here instead, under the frequency and program it belongs to, so the next listen can
 * draw it at once and the saved station list has something to show besides a number. A
 * station that rebrands sends a different file and this takes the new one; that is rare,
 * which is why the comparison below is allowed to be the cheap one.
 */
final class StationLogos
{
    /** Big enough for any logo a station has sent here, small enough to refuse a mistake. */
    private const MAXIMUM_BYTES = 2097152;

    private string $directory;

    public function __construct(string $directory)
    {
        $this->directory = rtrim($directory, '/');
    }

    public static function fromEnvironment(): self
    {
        $database = getenv('GUIDE_DB');
        $path     = $database === false || $database === '' ? dirname(__DIR__, 2) . '/data/guide.sqlite' : $database;

        return new self(dirname($path) . '/radio-logos');
    }

    public function getDirectory(): string
    {
        return $this->directory;
    }

    /**
     * The stored logo for a station's program, or null when none has been kept.
     */
    public function pathFor(float $frequency, int $program = 0): ?string
    {
        $name = $this->nameFor($frequency, $program);
        if ($name === null) {
            return null;
        }

        $path = "$this->directory/$name";

        return is_file($path) ? $path : null;
    }

    /**
     * Keep the logo a station has just sent, if it is not the one already kept.
     *
     * Called on every status poll, so the common answer is "the same file as last time"
     * and has to be cheap: a size check first, and only then the bytes.
     */
    public function remember(float $frequency, int $program, string $source): bool
    {
        $name = $this->nameFor($frequency, $program);
        if ($name === null || !is_file($source)) {
            return false;
        }

        $size = @filesize($source);
        if ($size === false || $size === 0 || $size > self::MAXIMUM_BYTES) {
            return false;
        }

        $path = "$this->directory/$name";
        if (is_file($path) && @filesize($path) === $size && @md5_file($path) === @md5_file($source)) {
            return false;
        }

        if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new RuntimeException("Unable to create $this->directory");
        }

        // Written beside the real name and moved onto it, so a reader never catches a
        // half-written picture: these are read by the page while the station is playing.
        $temporary = "$path." . bin2hex(random_bytes(4)) . '.part';

        if (@copy($source, $temporary) === false) {
            return false;
        }

        if (@rename($temporary, $path) === false) {
            @unlink($temporary);

            return false;
        }

        return true;
    }

    public function forget(float $frequency): void
    {
        $key = self::key($frequency);
        if ($key === null) {
            return;
        }

        foreach (glob("$this->directory/$key-*.png") ?: [] as $path) {
            @unlink($path);
        }
    }

    private function nameFor(float $frequency, int $program): ?string
    {
        $key = self::key($frequency);

        if ($key === null || $program < 0 || $program > 7) {
            return null;
        }

        return "$key-$program.png";
    }

    /**
     * Frequencies are held in kHz, as the station store holds them, so that 90.5 is a whole
     * number and two names can never disagree about the same station.
     */
    private static function key(float $frequency): ?string
    {
        if ($frequency < 64.0 || $frequency > 110.0) {
            return null;
        }

        return (string) (int) round($frequency * 1000);
    }
}
