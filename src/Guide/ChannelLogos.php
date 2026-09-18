<?php

declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave\Guide;

use RuntimeException;
use Skywave\Hdhomerun\DiscoveredDevice;

/**
 * Station logos, fetched once and then served from this machine.
 *
 * A broadcast carries no logo: ATSC's tables name a channel but never picture it. The
 * device's maker publishes one per channel, which is the only reliable source for them,
 * so they are fetched once and written next to the guide database. Everything after that
 * works without the internet, which is the point: only this refresh ever reaches out.
 *
 *   <data>/logos/4.1.png
 */
class ChannelLogos
{
    /** Where the device's maker publishes them; nothing else is downloaded. */
    private const IMAGE_HOST = 'img.hdhomerun.com';
    private const GUIDE_URL  = 'https://api.hdhomerun.com/api/guide.php?DeviceAuth=%s';

    /** A station logo is a few kilobytes; anything larger is not one. */
    private const MAXIMUM_BYTES = 1048576;

    private string $directory;
    private float $timeout;

    public function __construct(string $directory, float $timeout = 15.0)
    {
        $this->directory = rtrim($directory, '/');
        $this->timeout   = $timeout;
    }

    /**
     * Logos beside the guide database (GUIDE_DB), which every container mounts.
     */
    public static function fromEnvironment(): self
    {
        $database = getenv('GUIDE_DB');
        $path     = $database === false || $database === '' ? dirname(__DIR__, 2) . '/data/guide.sqlite' : $database;

        return new self(dirname($path) . '/logos');
    }

    public function getDirectory(): string
    {
        return $this->directory;
    }

    /**
     * The logo for a virtual channel, or null when there is none.
     */
    public function pathFor(string $virtual): ?string
    {
        if (!preg_match('/^\d{1,4}\.\d{1,4}$/', $virtual)) {
            return null;
        }

        $path = "$this->directory/$virtual.png";

        return is_file($path) ? $path : null;
    }

    /**
     * Fetch the logos this device's channels have, skipping the ones already here.
     *
     * @param bool $force fetch even the ones already on disk, e.g. after a rebrand
     * @return array{fetched: int, skipped: int, failed: int, channels: int}
     */
    public function refresh(DiscoveredDevice $device, bool $force = false): array
    {
        $auth = $device->getDeviceAuth();

        if ($auth === null || $auth === '') {
            throw new RuntimeException('That device does not offer a token for its maker\'s logo service');
        }

        if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new RuntimeException("Unable to create $this->directory");
        }

        $totals = ['fetched' => 0, 'skipped' => 0, 'failed' => 0, 'channels' => 0];

        foreach ($this->channelImages($auth) as $virtual => $url) {
            $totals['channels']++;
            $path = "$this->directory/$virtual.png";

            if (!$force && is_file($path)) {
                $totals['skipped']++;

                continue;
            }

            $image = $this->download($url);

            if ($image === null) {
                $totals['failed']++;

                continue;
            }

            // Written whole, then moved into place: a half-downloaded logo never shows.
            file_put_contents("$path.part", $image);
            rename("$path.part", $path);
            $totals['fetched']++;
        }

        return $totals;
    }

    /**
     * Virtual channel to logo address, from the device maker's guide service.
     *
     * @return array<string, string>
     */
    private function channelImages(string $auth): array
    {
        $body = @file_get_contents(sprintf(self::GUIDE_URL, urlencode($auth)), false, $this->context());

        if ($body === false) {
            throw new RuntimeException('Could not reach the logo service; logos need the internet, nothing else does');
        }

        $channels = json_decode($body, true);

        if (!is_array($channels)) {
            throw new RuntimeException('The logo service answered with something other than a channel list');
        }

        $images = [];

        foreach ($channels as $channel) {
            $virtual = (string) ($channel['GuideNumber'] ?? '');
            $url     = (string) ($channel['ImageURL'] ?? '');

            // Channels are matched by number, never by name: the service calls 4.1 WFORDT
            // where the broadcast calls it WFOR-TV.
            if (preg_match('/^\d{1,4}\.\d{1,4}$/', $virtual) && self::isAllowed($url)) {
                $images[$virtual] = $url;
            }
        }

        return $images;
    }

    private function download(string $url): ?string
    {
        // One byte past the limit, so a logo that fills it exactly is known to have been cut
        // off rather than mistaken for a whole one: a header check cannot tell the
        // difference, and a truncated image draws as half of one.
        $image = @file_get_contents($url, false, $this->context(), 0, self::MAXIMUM_BYTES + 1);

        if ($image === false || $image === '' || strlen($image) > self::MAXIMUM_BYTES) {
            return null;
        }

        // Trust the bytes, not the address: only an image is written to disk.
        return in_array(@getimagesizefromstring($image)[2] ?? null, [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_GIF], true)
            ? $image
            : null;
    }

    private static function isAllowed(string $url): bool
    {
        return parse_url($url, PHP_URL_SCHEME) === 'https' && parse_url($url, PHP_URL_HOST) === self::IMAGE_HOST;
    }

    /**
     * @return resource
     */
    private function context()
    {
        return stream_context_create([
            'http' => [
                'timeout'         => $this->timeout,
                'follow_location' => 1,
                'max_redirects'   => 3,
                'header'          => "User-Agent: skywave\r\n",
            ],
        ]);
    }
}
