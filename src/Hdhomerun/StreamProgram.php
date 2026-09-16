<?php declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave\Hdhomerun;

/**
 * One program line from /tunerN/streaminfo, e.g. "1: 7.1 KQED-HD (encrypted)".
 */
class StreamProgram
{
    public const TYPE_NORMAL    = 'normal';
    public const TYPE_CONTROL   = 'control';
    public const TYPE_ENCRYPTED = 'encrypted';
    public const TYPE_NO_DATA   = 'no_data';

    private string $line;
    private int $programNumber;
    private int $virtualMajor;
    private int $virtualMinor;
    private string $name;
    private string $type;

    public function __construct(string $line, int $programNumber, int $virtualMajor, int $virtualMinor, string $name, string $type)
    {
        $this->line          = $line;
        $this->programNumber = $programNumber;
        $this->virtualMajor  = $virtualMajor;
        $this->virtualMinor  = $virtualMinor;
        $this->name          = $name;
        $this->type          = $type;
    }

    public static function fromLine(string $line): ?self
    {
        if (!preg_match('/^\s*(\d+):\s*(\d+)(?:\.(\d+))?(.*)$/', $line, $match)) {
            return null;
        }

        // The name runs up to an optional trailing "(flags)" group.
        $name = trim(preg_replace('/\s*\([^)]*\)\s*$/', '', $match[4]));

        if (str_contains($line, '(control)')) {
            $type = self::TYPE_CONTROL;
        } elseif (str_contains($line, '(encrypted)')) {
            $type = self::TYPE_ENCRYPTED;
        } elseif (str_contains($line, '(no data)')) {
            $type = self::TYPE_NO_DATA;
        } else {
            $type = self::TYPE_NORMAL;
        }

        return new self($line, (int) $match[1], (int) $match[2], (int) ($match[3] ?? 0), $name, $type);
    }

    public function getLine(): string
    {
        return $this->line;
    }

    /** MPEG program number, the value /tunerN/program accepts. */
    public function getProgramNumber(): int
    {
        return $this->programNumber;
    }

    public function getVirtualMajor(): int
    {
        return $this->virtualMajor;
    }

    public function getVirtualMinor(): int
    {
        return $this->virtualMinor;
    }

    /**
     * Virtual channel as viewers know it, e.g. "7.1"; empty when not signaled.
     */
    public function getVirtualChannel(): string
    {
        if ($this->virtualMajor === 0) {
            return '';
        }

        return $this->virtualMinor === 0 ? (string) $this->virtualMajor : "$this->virtualMajor.$this->virtualMinor";
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * One of the TYPE_* constants.
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Whether a viewer could watch this program.
     */
    public function isWatchable(): bool
    {
        return $this->type === self::TYPE_NORMAL;
    }
}
