<?php declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave;

class Stream
{
    public const TYPE = [
          2 => 'MPEG-2 Video',
         27 => 'AVC Video',
         36 => 'HEVC Video',
        129 => 'ATSC AC-3 Audio',
    ];

    public const TYPE_MPEG2_VIDEO = 0x02;
    public const TYPE_AVC_VIDEO   = 0x1B;
    public const TYPE_HEVC_VIDEO  = 0x24;
    public const TYPE_AC3         = 0x81;

    private int     $type;
    private int     $pid;
    private ?string $lang;
    /** @var object[] keyed by descriptor name */
    private array   $descriptors;

    /**
     * @param object[] $descriptors keyed by descriptor name
     */
    public function __construct(int $type, int $pid, ?string $lang = null, array $descriptors = [])
    {
        $this->type        = $type;
        $this->pid         = $pid;
        $this->lang        = $lang;
        $this->descriptors = $descriptors;
    }

    /** @return object[] */
    public function getDescriptors(): array
    {
        return $this->descriptors;
    }

    public function getType(): int
    {
        return $this->type;
    }

    public function getPid(): int
    {
        return $this->pid;
    }

    public function getLang(): ?string
    {
        return $this->lang;
    }

    public function getTypeName(): string
    {
        return self::TYPE[$this->type] ?? 'UNKNOWN';
    }
}
