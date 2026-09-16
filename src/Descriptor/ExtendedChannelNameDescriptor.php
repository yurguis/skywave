<?php

declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave\Descriptor;

use Skywave\Structure\MultipleStringStructure;

class ExtendedChannelNameDescriptor
{
    public const DESCRIPTOR_ID   = 0xa0;
    public const DESCRIPTOR_NAME = 'Extended Channel Name Descriptor';

    private string $longChannelNameText;

    public function __construct(string $data)
    {
        $multipleStringStructure = new MultipleStringStructure($data);
        $strings                 = $multipleStringStructure->getStrings();
        if ($strings === []) {
            return;
        }

        $this->longChannelNameText = (string) reset($strings);
    }

    public function getName(): string
    {
        return self::DESCRIPTOR_NAME;
    }

    public function getLongChannelNameText(): string
    {
        return $this->longChannelNameText;
    }
}
