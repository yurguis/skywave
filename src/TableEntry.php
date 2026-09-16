<?php

declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave;

use Skywave\Descriptor\DescriptorFactory;
use Skywave\Io\BinaryReader;

class TableEntry
{
    private int $tableType;
    private int $pid;
    private int $versionNumber;
    private int $numberBytes;
    /** @var object[] keyed by descriptor name */
    private array $descriptors = [];

    public function __construct(
        int $tableType,
        int $pid,
        int $versionNumber,
        int $numberBytes,
        string $descriptors
    ) {
        $this->tableType     = $tableType;
        $this->pid           = $pid;
        $this->versionNumber = $versionNumber;
        $this->numberBytes   = $numberBytes;
        $this->parseDescriptors($descriptors);
    }

    public function getTableType(): int
    {
        return $this->tableType;
    }
    public function getPid(): int
    {
        return $this->pid;
    }
    public function getVersionNumber(): int
    {
        return $this->versionNumber;
    }
    public function getNumberBytes(): int
    {
        return $this->numberBytes;
    }
    /** @return object[] */
    public function getDescriptors(): array
    {
        return $this->descriptors;
    }

    public static function tableTypeName(int $type): string
    {
        static $names = [
            0x0000 => 'TVCT - current',
            0x0001 => 'TVCT - next',
            0x0002 => 'CVCT - current',
            0x0003 => 'CVCT - next',
            0x0004 => 'Channel ETT',
            0x0005 => 'DCCSCT',
        ];

        if (isset($names[$type])) {
            return $names[$type];
        }
        if ($type >= 0x0100 && $type <= 0x017F) {
            return sprintf('EIT-%d', $type - 0x0100);
        }
        if ($type >= 0x0200 && $type <= 0x027F) {
            return sprintf('Event ETT-%d', $type - 0x0200);
        }
        if ($type >= 0x0301 && $type <= 0x03FF) {
            return sprintf('RRT region %d', $type - 0x0300);
        }
        if ($type >= 0x1400 && $type <= 0x14FF) {
            return sprintf('DCCT-%d', $type - 0x1400);
        }

        return sprintf('Unknown (0x%04X)', $type);
    }

    private function parseDescriptors(string $descriptors): void
    {
        if ($descriptors === '') {
            return;
        }

        $reader = new BinaryReader($descriptors);
        while (!$reader->eof()) {
            $tag     = $reader->uint8();
            $length  = $reader->uint8();
            $payload = $length > 0 ? $reader->bytes($length) : '';

            $descriptor = DescriptorFactory::create($tag, $payload);
            if ($descriptor === null) {
                continue;
            }

            $this->descriptors[$descriptor->getName()] = $descriptor;
        }
    }
}
