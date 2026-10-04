<?php

declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

/**
 * Front controller for the web UI and its JSON API.
 *
 * Development server, with several workers so a running analysis does not block
 * status polling:
 *   PHP_CLI_SERVER_WORKERS=4 php -S 0.0.0.0:8080 -t public public/index.php
 *
 * HDHOMERUN_DEVICES=192.168.1.50,10.0.0.7 lists devices broadcast discovery cannot find.
 * Live playback needs ffmpeg on the PATH; see LiveStreams for its settings.
 * HD Radio needs nrsc5 as well, and RADIO_RTL_TCP or RADIO_DEVICE to say where its dongle is.
 */

use Skywave\Dvr\Recorder;
use Skywave\Dvr\RecordingPlayback;
use Skywave\Dvr\RecordingStore;
use Skywave\Dvr\SeriesRules;
use Skywave\Dvr\TunerReservations;
use Skywave\Guide\ChannelLogos;
use Skywave\Guide\GuideJobs;
use Skywave\Guide\GuideStore;
use Skywave\Guide\ProgrammeArtwork;
use Skywave\Hdhomerun\Discovery;
use Skywave\Radio\Receiver;
use Skywave\Radio\ScanJobs;
use Skywave\Radio\StationLogos;
use Skywave\Radio\StationStore;
use Skywave\Web\Api;
use Skywave\Web\LiveStreams;
use Skywave\Web\Logs;
use Symfony\Component\HttpFoundation\Request;

// Notices (e.g. deprecations from dependencies on newer PHP) belong in the server log,
// never inside a JSON response. The built-in server ignores display_errors=stderr.
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once dirname(__DIR__) . '/vendor/autoload.php';

$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if (PHP_SAPI === 'cli-server' && $path !== '/') {
    $file = realpath(__DIR__ . $path);

    if ($file !== false && is_file($file) && str_starts_with($file, __DIR__ . DIRECTORY_SEPARATOR)) {
        return false;
    }
}

// Live playback playlists and video segments written by ffmpeg.
if (str_starts_with($path, '/hls/')) {
    $file = preg_match('#^/hls/([a-f0-9]{16})/([^/]+)$#', $path, $match)
        ? LiveStreams::fromEnvironment()->resolveFile($match[1], $match[2])
        : null;
    $data = $file === null ? false : @file_get_contents($file);

    if ($data === false) {
        // Segments age out of the playlist and get deleted; players just move on.
        http_response_code(404);

        return;
    }

    $isPlaylist = str_ends_with($file, '.m3u8');

    header('Content-Type: ' . ($isPlaylist ? 'application/vnd.apple.mpegurl' : 'video/mp2t'));
    header('Cache-Control: ' . ($isPlaylist ? 'no-cache' : 'max-age=60'));
    echo $data;

    return;
}

// Pictures a radio station sent with its programme: an album cover, or the station's logo.
// They live with the session's playlist and go when it does, so nothing is kept for long.
if (preg_match('#^/radio/([a-f0-9]{16})/([^/]+)$#', $path, $match)) {
    $file = LiveStreams::fromEnvironment()->resolvePicture($match[1], rawurldecode($match[2]));

    if ($file === null) {
        http_response_code(404);

        return;
    }

    header('Content-Type: ' . (preg_match('/\.png$/i', $file) ? 'image/png' : 'image/jpeg'));
    header('Cache-Control: max-age=3600');
    readfile($file);

    return;
}

// The logo a station sent on an earlier listen, kept so the next one can draw it at once
// rather than showing a frequency on a grey square for the minute it takes to arrive again.
if (preg_match('#^/radio-logo/(\d{2,3}(?:\.\d)?)(?:/([0-7]))?$#', $path, $match)) {
    $file = StationLogos::fromEnvironment()->pathFor((float) $match[1], (int) ($match[2] ?? 0));

    if ($file === null) {
        http_response_code(404);

        return;
    }

    header('Content-Type: image/png');
    // Short: a station that rebrands should not be stuck behind a day-long cache.
    header('Cache-Control: max-age=300');
    readfile($file);

    return;
}

// Programme pictures, fetched once and served from here. A query rather than a path: a
// title can contain anything, including slashes, and none of it should become a path.
//
// A recording is asked for by id, because it keeps a copy of the picture it was made with:
// the one filed under the title belongs to the show, and a later fetch would change every
// recording of it at once. Recordings made before copies were kept fall back to the title.
if ($path === '/artwork') {
    $artwork = ProgrammeArtwork::fromEnvironment();
    $file    = null;

    if (isset($_GET['recording'])) {
        $store     = RecordingStore::fromEnvironment();
        $recording = $store->getRecording((int) $_GET['recording']);
        $own       = $recording['artworkPath'] ?? null;

        $file = $own === null ? null : $artwork->fileFor((string) $own);

        if ($file === null && $recording !== null) {
            $file = $artwork->pathFor((string) $recording['title']);
        }
    }

    $file ??= $artwork->pathFor((string) ($_GET['title'] ?? ''));

    if ($file === null) {
        http_response_code(404);

        return;
    }

    header('Cache-Control: public, max-age=86400');
    sendFile($file, 'image/jpeg');

    return;
}

// Station logos, fetched once and served from here so a page never reaches the internet.
if (preg_match('#^/logos/([0-9.]{3,9})\.png$#', $path, $match)) {
    $file = ChannelLogos::fromEnvironment()->pathFor($match[1]);

    if ($file === null) {
        // The page falls back to the channel's name, so a miss is ordinary.
        http_response_code(404);

        return;
    }

    header('Content-Type: image/png');
    header('Cache-Control: max-age=86400');
    readfile($file);

    return;
}

// Recordings: the converted playlist and segments, or the recorded file itself. Byte
// ranges let a browser seek an mp4 and let anything else be downloaded properly.
// Captions written beside a converted recording. A browser shows the ones carried inside a
// playlist by itself, but not the ones inside a plain file, so these are served as a track.
if (preg_match('#^/recordings/(\d+)/captions\.vtt$#', $path, $match)) {
    $file = RecordingPlayback::fromEnvironment()->captionsFile((int) $match[1]);

    if ($file === null) {
        http_response_code(404);

        return;
    }

    sendFile($file, 'text/vtt; charset=utf-8');

    return;
}

// One size of a converted recording, with its sound. The picture and the languages are
// separate files in a playlist, so they are muxed back together as this is sent: a stream
// copy, nothing re-encoded. It is made as it goes, so there is no length to announce and no
// resuming it -- unlike the recording itself below, which is a file and supports ranges.
if (preg_match('#^/recordings/(\d+)/download/(\d+)$#', $path, $match)) {
    $plan = RecordingPlayback::fromEnvironment()->downloadPlan((int) $match[1], (int) $match[2]);

    if ($plan === null) {
        http_response_code(404);

        return;
    }

    $name = str_replace(['"', "\r", "\n"], '', $plan['name']);

    header('Content-Type: video/mp4');
    header('Content-Disposition: attachment; filename="' . $name . '"');

    passthru($plan['command']);

    return;
}

if (preg_match('#^/recordings/(\d+)/(?:hls/([^/]+)|(file))$#', $path, $match)) {
    $playback = RecordingPlayback::fromEnvironment();
    $file     = ($match[3] ?? '') === 'file'
        ? $playback->sourceFile((int) $match[1])
        : $playback->resolveFile((int) $match[1], $match[2]);

    if ($file === null) {
        http_response_code(404);

        return;
    }

    $types = ['m3u8' => 'application/vnd.apple.mpegurl', 'ts' => 'video/mp2t', 'mp4' => 'video/mp4'];
    $type  = $types[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? 'application/octet-stream';

    // ?download asks the browser to save it rather than play it. Ranges still work, so an
    // interrupted download of a several-gigabyte recording can be resumed.
    if (isset($_GET['download'])) {
        // Named after the recording, not after the playlist's v0.ts.
        $name = str_replace(['"', "\r", "\n"], '', $playback->downloadName((int) $match[1], $file));
        header('Content-Disposition: attachment; filename="' . $name . '"');
    }

    sendFile($file, $type);

    return;
}

if (str_starts_with($path, '/api/')) {
    $hosts = array_filter(array_map('trim', explode(',', (string) getenv('HDHOMERUN_DEVICES'))));

    // A guide database that cannot be opened (e.g. an unwritable data directory) only
    // disables the guide; the rest of the app keeps working.
    try {
        $guide     = GuideStore::fromEnvironment();
        $guideJobs = GuideJobs::fromEnvironment();
    } catch (Throwable $e) {
        error_log('Program guide disabled: ' . $e->getMessage());
        $guide     = null;
        $guideJobs = null;
    }

    // Recordings share the guide database and need the recorder service to run them.
    try {
        $recordings   = RecordingStore::fromEnvironment();
        $recorder     = Recorder::fromEnvironment();
        $reservations = TunerReservations::fromEnvironment();
        $playback     = RecordingPlayback::fromEnvironment();
    } catch (Throwable $e) {
        error_log('Recording disabled: ' . $e->getMessage());
        $recordings   = null;
        $recorder     = null;
        $reservations = null;
        $playback     = null;
    }

    // A radio address that makes no sense only disables the radio; a typo in one setting
    // must not take the tuners with it.
    try {
        $radio = Receiver::fromEnvironment();
    } catch (Throwable $e) {
        error_log('HD Radio disabled: ' . $e->getMessage());
        $radio = null;
    }

    // Stations are kept in the guide's database. Without it the radio still plays; the
    // page falls back to remembering stations in the browser.
    try {
        $stations = $radio === null ? null : StationStore::fromEnvironment();
    } catch (Throwable $e) {
        error_log('Radio stations will not be kept: ' . $e->getMessage());
        $stations = null;
    }

    (new Api(
        new Discovery(),
        $hosts,
        LiveStreams::fromEnvironment(),
        $guide,
        $guideJobs,
        $recordings,
        $recorder,
        $reservations,
        $playback,
        Logs::fromEnvironment(),
        $guide !== null && $recordings !== null ? new SeriesRules($guide, $recordings) : null,
        $radio,
        $stations,
        $radio === null ? null : ScanJobs::fromEnvironment()
    ))
        ->handle(Request::createFromGlobals())
        ->send();

    return;
}

/**
 * Send a file, honouring a Range request so browsers can seek without downloading it all.
 */
function sendFile(string $file, string $type): void
{
    $size  = (int) filesize($file);
    $start = 0;
    $end   = $size - 1;
    $range = $_SERVER['HTTP_RANGE'] ?? '';

    if (preg_match('/^bytes=(\d*)-(\d*)$/', $range, $bounds) && ($bounds[1] !== '' || $bounds[2] !== '')) {
        // "bytes=-500" means the last 500 bytes, "bytes=500-" everything from 500 on.
        $start = $bounds[1] === '' ? max(0, $size - (int) $bounds[2]) : (int) $bounds[1];
        $end   = $bounds[1] === '' || $bounds[2] === '' ? $size - 1 : min((int) $bounds[2], $size - 1);

        if ($start > $end) {
            http_response_code(416);
            header("Content-Range: bytes */$size");

            return;
        }

        http_response_code(206);
        header("Content-Range: bytes $start-$end/$size");
    }

    header("Content-Type: $type");
    header('Accept-Ranges: bytes');
    header('Content-Length: ' . ($end - $start + 1));
    header('Cache-Control: ' . (str_ends_with($file, '.m3u8') ? 'no-cache' : 'max-age=60'));

    $handle = fopen($file, 'rb');

    if ($handle === false) {
        return;
    }

    fseek($handle, $start);

    // Send exactly the range that was asked for: reading past it would contradict the
    // Content-Length just sent.
    $remaining = $end - $start + 1;

    while ($remaining > 0 && !feof($handle)) {
        $chunk = (string) fread($handle, (int) min(131072, $remaining));

        echo $chunk;
        flush();
        $remaining -= strlen($chunk);
    }

    fclose($handle);
}

// Fingerprint the script and stylesheet URLs so a browser never keeps running an old
// app.js from its cache after an upgrade.
$html = (string) preg_replace_callback(
    '/\b(href|src)="((?:app|vendor\/hls\/hls\.min)\.(?:js|css))"/',
    fn (array $match) => sprintf('%s="%s?v=%s"', $match[1], $match[2], substr((string) @md5_file(__DIR__ . '/' . $match[2]), 0, 8)),
    (string) file_get_contents(__DIR__ . '/index.html')
);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');
echo $html;
