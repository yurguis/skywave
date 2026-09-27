# ffmpeg with an AC-4 decoder

ATSC 3.0 stations carry Dolby AC-4 audio. No released ffmpeg decodes it, which is
why Skywave plays the broadband stations silently. This directory builds
[librempeg](https://github.com/librempeg/librempeg) — a fork carrying a maintained
AC-4 decoder — on your machine, for your machine.

Two commands:

```sh
docker build -o data/ac4 -f docker/ac4/Dockerfile docker/ac4
docker compose -f docker-compose.yml -f docker-compose.ac4.yml up -d --build
```

Or name both files once in `.env` and carry on using plain `docker compose`:

```sh
echo 'COMPOSE_FILE=docker-compose.yml:docker-compose.ac4.yml' >> .env
```

`docker/ac4/build-librempeg.sh` runs the same build and reports honestly whether it
worked. That is not redundant: `docker build ... | tail` returns *tail's* exit
status, so a build that dies at `make` comes back as success.

The build writes `data/ac4/bin/ffmpeg` and `data/ac4/bin/ffprobe`, and the overlay
mounts that directory read-only into the web container. Expect it to take a while;
it compiles ffmpeg from source.

## What you are agreeing to

- **AC-4 is patented.** Dolby licenses it. Building a decoder for your own viewing
  is a different matter from shipping one to other people, which is why the binary
  is written to an ignored directory and never enters an image.
- **The build is `--enable-nonfree`**, because it links OpenSSL alongside GPL
  components. A binary built this way cannot be redistributed at all, by anyone.
  This is the lesser of the two constraints: the patent above would prevent
  redistribution under any licence string.
- **librempeg is a fork, not upstream ffmpeg.** Its AC-4 decoder was posted to
  ffmpeg-devel in 2020 as RFC/WIP and declined; the author has carried and improved
  it in his own tree since. Upstream ffmpeg still has no AC-4 decoder at all. Treat
  the output as unverified: it may decode some streams and not others.

Skywave commits the recipe, never the result. Nothing here is fetched or built
unless you run the command yourself.

## Why librempeg rather than the old patch

This recipe used to apply a 2020 patch to ffmpeg 6.1, because the patch applied to
nothing newer. librempeg is the same decoder by the same author, on an 8.x base,
with six years of fixes the snapshot predates.

Judged by ear on a PBS station over twenty minutes, it is not merely equivalent: the
intermittent hiccups that survived the audio-sync work in `LiveStreams` are gone, and
picture and sound stay together. That, rather than the version number, is why the
switch was made.

## What it does not change

Only the AC-4 stations use this binary, through `FFMPEG_AC4`. Every other channel
and every recording keeps using the ffmpeg in the image. The two are now the same
major version, so the split is no longer forced by a version pin — but it stays:
only these stations need AC-4, and routing every channel and every recording through
a fork is a far larger commitment than decoding audio for two of them.

If you skip the build entirely, everything works as before: the mount is an empty
directory, `FFMPEG_AC4` points at a binary that is not there, and those stations play
as silent video.

## Notes

- **The commit is pinned, and deliberately not to master's tip.** Commit `ac8be5e`
  (2026-09-25) removed `libswresample/` while `fftools` still includes its header, so
  the tip cannot build its own command-line tools. The pin is the commit before it.
- **`--enable-agpl` is mandatory, and omitting it fails silently.** librempeg gates
  the main program on it — `ffmpeg_deps="avcodec avfilter avformat threads agpl"` —
  where upstream ffmpeg attaches no licence dependency. Without the flag, configure
  drops `ffmpeg` from its program list without a word, `make` succeeds, and only
  `ffprobe` is installed. The Dockerfile checks `CONFIG_FFMPEG=yes` between configure
  and make rather than letting that cost a whole build.
- **`--enable-version3` is not a substitute for `--enable-nonfree`.** It configures,
  and reports a redistributable licence, but leaves configure building only avcodec,
  avfilter and avutil; `fftools` then cannot compile. Configure succeeding proves
  nothing; only `make` does.
- **The OpenSSL backend needs a trust store patched in.** `tls_openssl.c` loads one
  only when `ca_file` is given and never calls `SSL_CTX_set_default_verify_paths`,
  while `tls.h` now defaults `tls_verify` to 1. Unpatched, every station fails with
  `certificate verify failed`. Passing `-ca_file` is not a workaround: it fixes the
  manifest connection, and the DASH demuxer opens its segment fetches fresh without
  inheriting it. `patch-librempeg-tls.sh` adds the one call, and refuses to proceed
  if its anchor stops matching.
- The install path deliberately contains no `ffmpeg` directory component. Recording
  derives the probe binary with `str_replace('ffmpeg', 'ffprobe', ...)`, which
  rewrites *every* occurrence, so a directory such as `/opt/ffmpeg-ac4/` would
  produce a probe path that does not exist. `/opt/ac4/bin/` is safe.
- **`libxml2` is not optional.** ffmpeg only builds its DASH demuxer when libxml2 is
  present, and these stations are played from a DASH manifest. A build without it
  reports `ac4` among its decoders and is still unable to open the stream. Use
  `x264-dev`, not `x264-static`: the latter does not exist in alpine.
- The build is static against musl on purpose. These stations stream from a CDN, so
  the binary has to resolve DNS, which musl does without NSS; a static glibc build
  cannot, and would fail on every lookup.
- The Dockerfile refuses to finish unless the result has both the `ac4` decoder and
  the `dash` demuxer.
- Test a new binary inside the running container or the app image, **not** a bare
  alpine one. The TLS fix works by consulting the system trust store, so a container
  lacking `ca-certificates` fails for an unrelated reason that looks identical.
