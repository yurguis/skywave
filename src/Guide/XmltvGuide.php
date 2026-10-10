<?php

declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave\Guide;

use RuntimeException;
use Skywave\Hdhomerun\DiscoveredDevice;
use XMLReader;

/**
 * The guide SiliconDust publishes for a device, for the channels that broadcast none.
 *
 * Skywave's guide is read off the air and works with no internet at all, which is the point
 * of it. But a station that sends no EIT sends none, and nothing in this house can change
 * that: nine of this market's fifty-seven channels have never carried a guide, and the only
 * way to know what is on them is to ask somebody who publishes one.
 *
 * So this is a filler, not a replacement. It is off unless GUIDE_XMLTV is set, and what it
 * fetches is written only where the air said nothing -- see GuideStore::fillGapsFromXmltv.
 * Unreachable, it fails and the guide carries on as before.
 *
 * The same token already fetches channel logos (ChannelLogos), so nothing new is sent
 * anywhere: it is the device's own, and it is what their app uses to ask the same question.
 *
 * The free tier gives about two days. Fourteen needs their DVR subscription, and a shorter
 * answer than expected is how you can tell which one you have.
 */
final class XmltvGuide
{
    private const URL = 'https://api.hdhomerun.com/api/xmltv?DeviceAuth=%s';

    /** Beyond this the answer is not a guide, and will not be read as one. */
    private const MAX_BYTES = 64 * 1024 * 1024;

    private int $timeoutSeconds;

    public function __construct(int $timeoutSeconds = 60)
    {
        $this->timeoutSeconds = max(5, $timeoutSeconds);
    }

    /**
     * Enabled only when asked for. The guide works without it, and it reaches off the
     * machine, so it is never the default.
     */
    public static function fromEnvironment(): ?self
    {
        $wanted = getenv('GUIDE_XMLTV');

        if ($wanted === false || !in_array(strtolower(trim($wanted)), ['1', 'true', 'yes', 'on'], true)) {
            return null;
        }

        $timeout = getenv('GUIDE_XMLTV_TIMEOUT');

        return new self($timeout === false || !is_numeric($timeout) ? 60 : (int) $timeout);
    }

    /**
     * Fetch the guide for a device and write it under what its channels already say.
     *
     * @param string $host as the lineup is filed under it, which is what the caller used
     *
     * @return array{channels: int, added: int, skipped: int}
     */
    public function fill(string $host, DiscoveredDevice $device, GuideStore $store, ?int $now = null): array
    {
        $auth = $device->getDeviceAuth();

        if ($auth === null || $auth === '') {
            throw new RuntimeException('That device does not offer a token for its maker\'s guide service');
        }

        // The host is passed rather than taken from the device: it is what the lineup is
        // filed under, and a device found by discovery can name itself differently.
        return $store->fillGapsFromXmltv($host, $this->fetch($auth), $now ?? time());
    }

    /**
     * The programmes in the feed, by the channel number they belong to.
     *
     * @return array<string, list<array{start: int, duration: int, title: string, description: ?string, rating: ?string}>>
     */
    public function fetch(string $auth): array
    {
        return self::parse($this->download(sprintf(self::URL, rawurlencode($auth))));
    }

    /**
     * Read the XMLTV, keeping only what the guide shows.
     *
     * Read as a stream rather than loaded whole: the answer is three megabytes and climbs
     * with the days bought, and a document tree of it costs many times that.
     *
     * A channel is named several ways over -- a call sign, the number, the number and the
     * call sign together -- and only the bare number identifies it here, because that is
     * what the lineup is keyed by.
     *
     * One identifier can be listed under more than one number, and is: WFOR arrives twice,
     * as 4.1 and as 104.1, which are its ATSC 1.0 and 3.0 broadcasts carrying the same
     * schedule. Both numbers are kept and both are filled, because a lineup may hold either
     * or both and neither is the wrong answer.
     *
     * @return array<string, list<array{start: int, duration: int, title: string, description: ?string, rating: ?string}>>
     */
    public static function parse(string $xml): array
    {
        $reader = new XMLReader();

        if (@$reader->XML($xml, 'UTF-8', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING) !== true) {
            throw new RuntimeException('The guide service answered with something that is not XMLTV');
        }

        $numbers    = [];
        $programmes = [];
        $root       = null;

        while (@$reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT) {
                continue;
            }

            // XMLReader::XML() only prepares the read; rubbish is not refused until it is
            // actually read, and then it is refused silently. An answer with no element in
            // it at all was not a guide, whatever it was.
            $root ??= $reader->name;

            if ($reader->name === 'channel') {
                $id   = (string) $reader->getAttribute('id');
                $node = @simplexml_load_string((string) $reader->readOuterXml(), 'SimpleXMLElement', LIBXML_NONET);

                foreach ($node === false ? [] : $node->{'display-name'} as $name) {
                    $text = trim((string) $name);

                    if ($id !== '' && preg_match('/^\d{1,4}\.\d{1,3}$/', $text)) {
                        $numbers[$id][$text] = true;

                        break;
                    }
                }

                continue;
            }

            if ($reader->name !== 'programme') {
                continue;
            }

            $channel = (string) $reader->getAttribute('channel');
            $start   = self::moment((string) $reader->getAttribute('start'));
            $stop    = self::moment((string) $reader->getAttribute('stop'));

            if ($channel === '' || $start === null || $stop === null || $stop <= $start) {
                continue;
            }

            $node = @simplexml_load_string((string) $reader->readOuterXml(), 'SimpleXMLElement', LIBXML_NONET);

            if ($node === false) {
                continue;
            }

            $title = trim((string) $node->title);

            if ($title === '') {
                continue;
            }

            $description = trim((string) $node->desc);
            $rating      = trim((string) ($node->rating->value ?? ''));

            $programmes[$channel][] = [
                'start'       => $start,
                'duration'    => $stop - $start,
                'title'       => $title,
                'description' => $description === '' ? null : $description,
                'rating'      => $rating === '' ? null : $rating,
            ];
        }

        $reader->close();

        if ($root !== 'tv') {
            throw new RuntimeException('The guide service answered with something that is not XMLTV');
        }

        // Keyed by the channel number rather than the service's own identifier, which means
        // nothing to a lineup read off the air.
        $byNumber = [];

        foreach ($programmes as $id => $list) {
            foreach (array_keys($numbers[$id] ?? []) as $number) {
                $byNumber[$number] = array_merge($byNumber[$number] ?? [], $list);
            }
        }

        return $byNumber;
    }

    /**
     * An XMLTV timestamp: "20261010183000 -0400", and sometimes without the offset.
     */
    private static function moment(string $value): ?int
    {
        if (!preg_match('/^(\d{14})(?:\s*([+-]\d{4}))?$/', trim($value), $match)) {
            return null;
        }

        $when = strtotime($match[1] . ($match[2] ?? '+0000'));

        return $when === false ? null : $when;
    }

    private function download(string $url): string
    {
        $context = stream_context_create(['http' => [
            'timeout'       => $this->timeoutSeconds,
            'ignore_errors' => true,
            'header'        => "Accept: application/xml\r\nUser-Agent: Skywave\r\n",
        ]]);

        $body = @file_get_contents($url, false, $context, 0, self::MAX_BYTES);

        if ($body === false || $body === '') {
            throw new RuntimeException('The guide service could not be reached');
        }

        // $http_response_header is set by the stream wrapper beside the body.
        $status = 0;

        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $match)) {
                $status = (int) $match[1];
            }
        }

        if ($status !== 0 && $status !== 200) {
            throw new RuntimeException("The guide service answered $status");
        }

        return $body;
    }
}
