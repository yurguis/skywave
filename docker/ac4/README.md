# ffmpeg with an AC-4 decoder

ATSC 3.0 stations carry Dolby AC-4 audio. No released ffmpeg decodes it, which is
why Skywave plays the broadband stations silently. A decoder was written for
ffmpeg in 2020 and never merged; this directory builds ffmpeg with that patch
applied, on your machine, for your machine.

Two commands:

```sh
docker build -o data/ac4 -f docker/ac4/Dockerfile docker/ac4
docker compose -f docker-compose.yml -f docker-compose.ac4.yml up -d --build
```

Or name both files once in `.env` and carry on using plain `docker compose`:

```sh
echo 'COMPOSE_FILE=docker-compose.yml:docker-compose.ac4.yml' >> .env
```

The build writes `data/ac4/bin/ffmpeg` and `data/ac4/bin/ffprobe`, and the overlay
mounts that directory read-only into the web container. Expect it to take a
while; it compiles ffmpeg from source.

## What you are agreeing to

- **AC-4 is patented.** Dolby licenses it. Building a decoder for your own
  viewing is a different matter from shipping one to other people, which is why
  the binary is written to an ignored directory and never enters an image.
- **The build is `--enable-nonfree`**, because it links OpenSSL alongside GPL
  components. A binary built this way cannot be redistributed at all, by anyone.
- **The patch is unreviewed by upstream.** It was posted as RFC/WIP, and ffmpeg
  declined it over unresolved technical objections. Treat the output as
  unverified: it may decode some streams and not others.

Skywave commits the recipe, never the result. Nothing here is fetched or built
unless you run the command yourself.

## What it does not change

Only the AC-4 stations use this binary, through `FFMPEG_AC4`. Every other
channel and every recording keeps using the ffmpeg in the image, which is several
major versions newer — the patch exists only for 6.1, and nothing else should be
dragged back that far to gain sound on a couple of stations.

If you skip the build entirely, everything works as before: the mount is an empty
directory, `FFMPEG_AC4` points at a binary that is not there, and those stations
play as silent video.

## Notes

- The install path deliberately contains no `ffmpeg` directory component.
  Recording derives the probe binary with
  `str_replace('ffmpeg', 'ffprobe', ...)`, which rewrites *every* occurrence, so
  a directory such as `/opt/ffmpeg-ac4/` would produce a probe path that does
  not exist. `/opt/ac4/bin/` is safe.
- The patch is pinned by SHA-256 in the Dockerfile. If upstream changes it the
  build fails rather than compiling something unexamined.
- ffmpeg 6.1 is pinned because the patch does not apply to 7.x or 8.x.
- The build is static against musl on purpose. These stations stream from a CDN,
  so the binary has to resolve DNS, which musl does without NSS; a static glibc
  build cannot, and would fail on every lookup.
- The Dockerfile refuses to finish unless the result has both the `ac4` decoder
  and the `dash` demuxer. A build missing libxml2 reports `ac4` happily and is
  still unable to open the stream.
