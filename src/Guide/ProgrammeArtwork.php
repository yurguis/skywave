<?php

declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave\Guide;

use RuntimeException;

/**
 * Pictures for programmes, from TVmaze.
 *
 * A broadcast names what is on but never pictures it, the same problem the station logos
 * have. Artwork is fetched once, written beside the guide database and served from there,
 * so a page never reaches the internet and everything works without one.
 *
 * A title is all the air gives us, so a title is all this matches on: exactly, lowercased,
 * with its spaces tidied. Nothing fuzzy. Matching "Caregiving Shorts (Well Beings/Weta)" to
 * some other programme would be worse than showing no picture at all.
 */
class ProgrammeArtwork
{
    private const SEARCH_URL = 'https://api.tvmaze.com/singlesearch/shows?q=%s';

    private const IMAGE_HOST = 'static.tvmaze.com';

    /** Their images run to about 20 KB; this is room to spare, not an expectation. */
    private const MAXIMUM_BYTES = 1048576;

    /** TVmaze asks for about twenty calls per ten seconds. This is well inside that. */
    private const PAUSE_MICROSECONDS = 600000;

    /** A programme missing today may be added later, so a miss is not forever. */
    public const MISS_SECONDS = 30 * 86400;

    private string $directory;

    private float $timeout;

    public function __construct(string $directory, float $timeout = 15.0)
    {
        $this->directory = rtrim($directory, '/');
        $this->timeout   = $timeout;
    }

    /**
     * Artwork beside the guide database (GUIDE_DB), which every container mounts.
     */
    public static function fromEnvironment(): self
    {
        $database = getenv('GUIDE_DB');
        $path     = $database === false || $database === '' ? dirname(__DIR__, 2) . '/data/guide.sqlite' : $database;

        return new self(dirname($path) . '/artwork');
    }

    public function getDirectory(): string
    {
        return $this->directory;
    }

    /**
     * What a title is filed under: the title itself, tidied, then hashed so that any
     * punctuation a broadcast sends cannot become a path.
     */
    public static function key(string $title): string
    {
        $tidy = trim((string) preg_replace('/\s+/u', ' ', $title));

        return $tidy === '' ? '' : substr(sha1(mb_strtolower($tidy, 'UTF-8')), 0, 16);
    }

    /**
     * The picture for a programme, or null when there is none here.
     */
    public function pathFor(string $title): ?string
    {
        $key = self::key($title);

        if ($key === '') {
            return null;
        }

        $path = "$this->directory/$key.jpg";

        return is_file($path) ? $path : null;
    }

    /**
     * Fetch pictures for titles that have none, remembering the ones TVmaze does not know.
     *
     * Misses matter as much as hits: a channel running "Paid Programming" two dozen times a
     * day would otherwise be looked up two dozen times a day, forever.
     *
     * @param list<string> $titles
     * @return array{fetched: int, skipped: int, missing: int, failed: int}
     */
    public function refresh(array $titles, GuideStore $store, bool $force = false): array
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new RuntimeException("Unable to create $this->directory");
        }

        $totals = ['fetched' => 0, 'skipped' => 0, 'missing' => 0, 'failed' => 0];
        $seen   = [];

        foreach ($titles as $title) {
            $key = self::key($title);

            if ($key === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $path       = "$this->directory/$key.jpg";

            if (!$force && is_file($path)) {
                $totals['skipped']++;

                continue;
            }

            if (!$force && $store->artworkMissedRecently($key, time() - self::MISS_SECONDS)) {
                $totals['skipped']++;

                continue;
            }

            $url = $this->lookup($title);

            if ($url === null) {
                $store->rememberArtworkMiss($key, $title);
                $totals['missing']++;
                usleep(self::PAUSE_MICROSECONDS);

                continue;
            }

            $image = $this->download($url);
            usleep(self::PAUSE_MICROSECONDS);

            if ($image === null) {
                $totals['failed']++;

                continue;
            }

            // Written whole, then moved into place: a half-downloaded picture never shows.
            file_put_contents("$path.part", $image);
            rename("$path.part", $path);
            $totals['fetched']++;
        }

        return $totals;
    }

    /**
     * The address of a programme's picture, or null when TVmaze has no such programme.
     */
    private function lookup(string $title): ?string
    {
        $body = @file_get_contents(sprintf(self::SEARCH_URL, urlencode($title)), false, $this->context());

        // A title they do not know answers 404 with a body of "null", which reads as a miss
        // rather than as a failure worth retrying.
        if ($body === false || $body === '' || $body === 'null') {
            return null;
        }

        $show = json_decode($body, true);

        if (!is_array($show)) {
            return null;
        }

        $url = (string) ($show['image']['original'] ?? $show['image']['medium'] ?? '');

        return self::isAllowed($url) ? $url : null;
    }

    private function download(string $url): ?string
    {
        $image = @file_get_contents($url, false, $this->context(), 0, self::MAXIMUM_BYTES);

        if ($image === false || $image === '') {
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
                'ignore_errors'   => true,
                'header'          => "User-Agent: skywave\r\n",
            ],
        ]);
    }
}
