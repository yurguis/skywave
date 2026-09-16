<?php

declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave\Descriptor;

class DescriptorFactory
{
    /** Map of descriptor_tag => fully-qualified class name. */
    private const REGISTRY = [
        Ac3AudioStreamDescriptor::DESCRIPTOR_ID      => Ac3AudioStreamDescriptor::class,
        CaptionServiceDescriptor::DESCRIPTOR_ID      => CaptionServiceDescriptor::class,
        ContentAdvisoryDescriptor::DESCRIPTOR_ID     => ContentAdvisoryDescriptor::class,
        ExtendedChannelNameDescriptor::DESCRIPTOR_ID => ExtendedChannelNameDescriptor::class,
        ServiceLocationDescriptor::DESCRIPTOR_ID     => ServiceLocationDescriptor::class,
    ];

    public static function create(int $tag, string $payload): ?object
    {
        $class = self::REGISTRY[$tag] ?? null;
        if ($class === null) {
            return null;
        }

        return new $class($payload);
    }
}
