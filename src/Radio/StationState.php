<?php

declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave\Radio;

/**
 * What nrsc5 has said about the station it is tuned to, read from its log a line at a time.
 *
 * nrsc5 has no other way of telling anyone anything. The station's name, what is playing,
 * how good the signal is and which pictures it has sent all arrive as lines of text on
 * standard error, each stamped with the time, so this is a table of those lines and what
 * each one means.
 *
 * Song details are only printed for the program being played. Pictures are not: every
 * program's album covers and logos arrive on the one frequency, each on its own data port,
 * so which port belongs to which program has to be worked out from the station's service
 * list before a picture can be believed to be about this program at all.
 */
final class StationState
{
    /** MIME hashes HD Radio uses for the two pictures worth showing. */
    private const MIME_STATION_LOGO = 'D9C72536';
    private const MIME_ALBUM_ART    = 'BE4B7536';

    private int $program;

    private bool $synchronized = false;
    private ?string $station   = null;
    private ?string $slogan    = null;
    private ?string $message   = null;
    private ?string $title     = null;
    private ?string $artist    = null;
    private ?string $album     = null;
    private ?string $genre     = null;
    private ?string $alert     = null;
    private ?string $error     = null;
    private ?float $bitrate    = null;
    /** @var array<int, array<string, mixed>> traffic maps the station drew, by how far out they reach */
    private array $traffic = [];

    /** HDC's coding mode, as the audio itself reports it. Every station is HDC; the mode varies. */
    private ?int $codecMode = null;
    private ?float $mer     = null;
    private ?float $ber     = null;
    private ?float $gain    = null;

    /** @var array<int, array{number: int, name: ?string, type: ?string}> programs on this frequency */
    private array $programs = [];

    /** @var array<string, array{program: int, kind: string}> data port (hex) to what it carries */
    private array $ports = [];

    /** The service whose components are being listed, as a program number. */
    private ?int $listing = null;

    /** @var array<int, string> album covers received, by the number the song refers to them by */
    private array $covers = [];

    private ?string $logo = null;

    /** The cover the song now playing asked for, or null when it asked for the logo. */
    private ?int $wantedCover = null;

    /**
     * @param int $program the program being played, counting from zero (HD1 is 0)
     */
    public function __construct(int $program = 0)
    {
        $this->program = $program;
    }

    /**
     * Take one line of nrsc5's log.
     *
     * @return bool whether it changed anything worth telling a listener
     */
    public function apply(string $line): bool
    {
        // Every line opens with the time of day; nothing here needs it.
        if (!preg_match('/^\d{2}:\d{2}:\d{2} (.*)$/', rtrim($line, "\r\n"), $match)) {
            return false;
        }

        $before = $this->toArray();
        $text   = $match[1];

        // The components of a service are listed indented beneath it, so the service has
        // to be remembered while they go by, and forgotten at the first line that is not one.
        if (preg_match('/^\s+(Audio|Data) component: (.*)$/', $text, $component)) {
            $this->applyComponent($component[1], $component[2]);

            return $before !== $this->toArray();
        }

        $this->listing = null;
        $this->applyLine($text);

        return $before !== $this->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        ksort($this->programs);

        return [
            'synchronized' => $this->synchronized,
            'station'      => $this->station,
            'slogan'       => $this->slogan,
            'message'      => $this->message,
            'title'        => $this->title,
            'artist'       => $this->artist,
            'album'        => $this->album,
            'genre'        => $this->genre,
            'alert'        => $this->alert,
            'bitrate'      => $this->bitrate,
            'codecMode'    => $this->codecMode,
            'traffic'      => $this->trafficMaps(),
            'mer'          => $this->mer,
            'ber'          => $this->ber,
            'gain'         => $this->gain,
            'programs'     => array_values($this->programs),
            'art'          => $this->wantedCover === null ? null : ($this->covers[$this->wantedCover] ?? null),
            'logo'         => $this->logo,
            'error'        => $this->error,
        ];
    }

    public function isSynchronized(): bool
    {
        return $this->synchronized;
    }

    /**
     * Why nrsc5 gave up, in its own words, or null when it has not.
     */
    public function getError(): ?string
    {
        return $this->error;
    }

    private function applyLine(string $text): void
    {
        if ($text === 'Synchronized') {
            $this->synchronized = true;
            $this->error        = null;

            return;
        }

        if ($text === 'Lost synchronization') {
            $this->synchronized = false;

            return;
        }

        if ($text === 'Alert ended') {
            $this->alert = null;

            return;
        }

        // nrsc5 says why it is stopping and then stops. These are the last thing it prints.
        if (preg_match('/( failed\.?(: .*)?|^Invalid .*\.|^Unable to open .*\.|^Lost device)$/', $text)) {
            $this->error = $text;

            return;
        }

        if (!preg_match('/^([A-Za-z][A-Za-z ]*?)(?: (\d+))?: (.*)$/', $text, $match)) {
            return;
        }

        [, $label, $number, $value] = $match;
        $value                      = trim($value);

        switch ($label) {
            case 'Station name':
                $this->station = self::text($value);

                break;

            case 'Slogan':
                $this->slogan = self::text($value);

                break;

            case 'Message':
                $this->message = self::text($value);

                break;

            case 'Title':
                $this->title = self::text($value);

                break;

            case 'Artist':
                $this->artist = self::text($value);

                break;

            case 'Album':
                $this->album = self::text($value);

                break;

            case 'Genre':
                $this->genre = self::text($value);

                break;

            case 'Alert':
                $this->alert = self::text($value);

                break;

            case 'Audio bit rate':
                $this->bitrate = (float) $value;

                break;

            case 'MER':
                // Reported for the sidebands either side of the analogue signal. The worse
                // of the two is the one that decides whether the audio holds together.
                if (preg_match('/^(-?[\d.]+) dB \(lower\), (-?[\d.]+) dB \(upper\)$/', $value, $sides)) {
                    $this->mer = min((float) $sides[1], (float) $sides[2]);
                }

                break;

            case 'BER':
                if (preg_match('/^([\d.]+), avg: ([\d.]+)/', $value, $rates)) {
                    $this->ber = (float) $rates[2];
                }

                break;

            case 'Best gain':
                $this->gain = (float) $value;

                break;

                // The same news from two places. "Audio service" comes from the audio itself and
                // is always there; "Audio program" is the station's own description, which not
                // every station sends. Either one proves the program exists.
            case 'Audio service':
            case 'Audio program':
                if ($number !== '' && preg_match('/type: ([^,]+)/', $value, $type)) {
                    $this->rememberProgram((int) $number, null, trim($type[1]));
                }

                // Only the program being listened to: the others describe their own audio.
                if ((int) $number === $this->program && preg_match('/codec: (\d+)/', $value, $codec)) {
                    $this->codecMode = (int) $codec[1];
                }

                break;

            case 'HERE Image':
                $this->applyHereImage($value);

                break;

            case 'SIG Service':
                // Services are numbered from one and programs from zero: service 1 is HD1,
                // which nrsc5 calls program 0.
                if (preg_match('/^type=(audio|data) number=(\d+) name=(.*)$/', $value, $service)) {
                    $this->listing = $service[1] === 'audio' ? (int) $service[2] - 1 : null;

                    if ($this->listing !== null && $this->listing >= 0) {
                        $this->rememberProgram($this->listing, self::text($service[3]), null);
                    }
                }

                break;

            case 'LOT file':
                $this->applyFile($value);

                break;

            case 'XHDR':
                $this->applyPictureChoice($value);

                break;
        }
    }

    /**
     * The tiles of the traffic map, in reading order: west to east, north to south.
     *
     * @return list<array<string, mixed>>
     */
    private function trafficMaps(): array
    {
        ksort($this->traffic);

        return array_values($this->traffic);
    }

    /**
     * A map the station drew and broadcast: how the roads are moving, or where it is raining.
     *
     * Only some stations send these, and only through the HERE data service. nrsc5 unpacks
     * them itself and says where on earth each one belongs, so there is nothing to decode
     * here: the picture is already a map, with streets and names drawn on it, and the
     * corners say where to put it. It is written beside the playlist under the time it was
     * made and its own name, which is how it is found again.
     *
     * Nine arrive and they are a three by three grid of tiles, not nine views of one place:
     * trafficMap_ROW_COLUMN, row 0 north to row 2 south, column 0 west to column 2 east.
     * Assembled they are 600x600 over the market. Pinned by eye on 104.3: 0_0 is Alligator
     * Alley, 0_2 is Fort Lauderdale, 2_0 is Fortymile Bend, 2_2 is Miami and Key Biscayne.
     *
     * The corners nrsc5 prints are not this tile's. They are nested -- all nine share one
     * centre and grow 27, 82 and 137 km -- which reads like three zoom levels and is not
     * what the pictures are. 0_2 claims a northern edge of 26.03 and plainly shows Fort
     * Lauderdale, which is north of it. They are kept here because the broadcast sent them,
     * and used for nothing.
     */
    private function applyHereImage(string $fields): void
    {
        $shape = '/^type=(\w+).*\btime=(\S+), lat1=([-\d.]+), lon1=([-\d.]+), lat2=([-\d.]+), lon2=([-\d.]+), name=(\S+), size=\d+/';

        if (!preg_match($shape, $fields, $match)) {
            return;
        }

        // Weather is a transparent sheet of rain and needs a map under it to mean anything,
        // which is a different piece of work; traffic stands on its own.
        if ($match[1] !== 'TRAFFIC' || !preg_match('/^trafficMap_(\d)_(\d)_/', $match[7], $cell)) {
            return;
        }

        $at = strtotime($match[2]);

        if ($at === false) {
            return;
        }

        $this->traffic["$cell[1]_$cell[2]"] = [
            'row'    => (int) $cell[1],
            'column' => (int) $cell[2],
            'file'   => $at . '_' . $match[7],
            'at'     => $at,
            'north'  => (float) $match[3],
            'west'   => (float) $match[4],
            'south'  => (float) $match[5],
            'east'   => (float) $match[6],
        ];
    }

    /**
     * A data port beneath the service being listed: note what it carries and for whom.
     */
    private function applyComponent(string $kind, string $fields): void
    {
        if ($kind !== 'Data' || $this->listing === null) {
            return;
        }

        if (!preg_match('/port=([0-9A-Fa-f]+).* mime=([0-9A-Fa-f]{8})/', $fields, $match)) {
            return;
        }

        $mime = strtoupper($match[2]);

        if ($mime === self::MIME_STATION_LOGO || $mime === self::MIME_ALBUM_ART) {
            $this->ports[strtoupper($match[1])] = [
                'program' => $this->listing,
                'kind'    => $mime === self::MIME_STATION_LOGO ? 'logo' : 'art',
            ];
        }
    }

    /**
     * A file the station finished sending. nrsc5 has already written it, named by its
     * number and then its own name, which is how it is found again.
     */
    private function applyFile(string $fields): void
    {
        if (!preg_match('/^port=([0-9A-Fa-f]+) lot=(\d+) name=(.+?) size=\d+ mime=([0-9A-Fa-f]{8})/', $fields, $match)) {
            return;
        }

        $name = $match[3];

        // Only pictures, and only names that are plainly a file: what a broadcast sends is
        // going to be served to a browser from the session's own directory.
        //
        // The dollar is in here because stations use it. A logo arrives named for its call
        // sign and program -- WRTO sends SLWRTO$$010003META.png -- and a list without it
        // threw away every picture the station sent. What the list is for is refusing a
        // name that is a path rather than a file, and it still does: no separator, no dots
        // leading anywhere, nothing that is not a picture.
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._$-]*\.(jpe?g|png)$/i', $name)) {
            return;
        }

        $port = $this->ports[strtoupper($match[1])] ?? null;
        $mime = strtoupper($match[4]);

        // Until the service list arrives nothing says whose picture this is; once it has,
        // another program's pictures are not this program's to show.
        if ($port !== null && $port['program'] !== $this->program) {
            return;
        }

        $file = "{$match[2]}_$name";
        $kind = $port['kind'] ?? ($mime === self::MIME_STATION_LOGO ? 'logo' : 'art');

        if ($kind === 'logo') {
            $this->logo = $file;
        } else {
            $this->covers[(int) $match[2]] = $file;

            // A long listen would otherwise remember every cover it was ever sent.
            if (count($this->covers) > 32) {
                $this->covers = array_slice($this->covers, -32, null, true);
            }
        }
    }

    /**
     * The song says which picture goes with it: a cover by number, or the station's logo.
     */
    private function applyPictureChoice(string $fields): void
    {
        if (!preg_match('/^(\d+) [0-9A-Fa-f]{8} (-?\d+)$/', $fields, $match)) {
            return;
        }

        $this->wantedCover = $match[1] === '0' && (int) $match[2] >= 0 ? (int) $match[2] : null;
    }

    private function rememberProgram(int $number, ?string $name, ?string $type): void
    {
        $known = $this->programs[$number] ?? ['number' => $number, 'name' => null, 'type' => null];

        $this->programs[$number] = [
            'number' => $number,
            'name'   => $name ?? $known['name'],
            // "None" is what a station sends when it never said, which is not a genre.
            'type' => $type !== null && strcasecmp($type, 'None') !== 0 ? $type : $known['type'],
        ];
    }

    /**
     * Broadcast text, padded with spaces as often as not, or null when there is none.
     */
    private static function text(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
