# nrsc5, the HD Radio decoder

HD Radio stations are received as raw samples from an RTL-SDR dongle, and everything
that turns those into sound is [nrsc5](https://github.com/theori-io/nrsc5). Skywave
does not ship it. This directory builds it on your machine, for your machine.

Two commands:

```sh
docker/nrsc5/build-nrsc5.sh
docker compose -f docker-compose.yml -f docker-compose.radio.yml up -d --build
```

Or name both files once in `.env` and carry on using plain `docker compose`:

```sh
echo 'COMPOSE_FILE=docker-compose.yml:docker-compose.radio.yml' >> .env
```

The build writes `data/nrsc5/bin/nrsc5` and the four shared libraries it needs to
`data/nrsc5/lib`, and the overlay mounts that directory read-only into the web
container. It takes about a minute.

Then tell Skywave where the dongle is, in `.env`: `RADIO_RTL_TCP` for one shared by
`rtl_tcp`, or `RADIO_DEVICE` for one plugged in. Until one of them is set the radio
stays off, whether or not nrsc5 is there.

## What you are agreeing to

- **The codec is proprietary.** HD Radio's audio is HDC, a variant of HE-AAC owned
  and licensed by Xperi. nrsc5 decodes it by downloading faad2 and patching it during
  its own build. Building a decoder for your own listening is a different matter from
  shipping one to other people, which is why the binary is written to an ignored
  directory and never enters an image.
- **nrsc5 is GPL-3.0, and Skywave is MIT.** Skywave runs nrsc5 as a separate program
  and reads what it prints; it contains none of its code. A binary you build is
  yours to run. Passing it on to someone else is distribution, and brings the GPL's
  terms with it.
- **It reaches the internet once, at build time**, for nrsc5 itself and for the
  faad2 source it patches. Nothing is fetched when a station plays.

Skywave commits the recipe, never the result. Nothing here is fetched or built
unless you run the command yourself.

## Use the script, not the bare command

`docker build -o data/nrsc5 -f docker/nrsc5/Dockerfile docker/nrsc5` works, with one
trap. When the output directory does not exist yet, docker creates it readable by
its owner alone. The web container runs as a different user, so the mount shows it a
directory it cannot open, and the page says nrsc5 is not installed while it sits
right there. A directory that already exists is left as it was, so the script makes
it first.

The other thing the script says plainly: `data/` itself may not be yours. Docker
creates it, as root, the first time it mounts the recordings folder. Then either
take it back,

```sh
sudo chown "$(id -u):$(id -g)" data
```

or build somewhere else and say where in `.env`:

```sh
docker/nrsc5/build-nrsc5.sh ~/skywave-nrsc5
echo "NRSC5_DIR=$HOME/skywave-nrsc5" >> .env
```

## Notes

- **The commit is pinned**, so the build is repeatable and a change upstream cannot
  quietly become part of the result. Pass `--build-arg NRSC5_COMMIT=<sha>` to try a
  newer one.
- **It is not a static binary, because it cannot be.** nrsc5 writes its sound
  through libao, which finds its drivers at run time and has no static form on
  Linux. So the binary is built knowing its libraries will be at `/opt/nrsc5/lib`,
  and they are exported beside it. That is also why it is built on the same Alpine
  release as the web image: it runs against that image's C library.
- **The build proves itself before exporting anything.** nrsc5 ships a capture of a
  real station; the last step decodes it and fails the build unless a station name
  and a plausible amount of sound come out. A build whose patched faad2 went wrong
  would otherwise start, find the station and play silence.
- **No SSE or NEON.** Both speed nrsc5 up, and a recipe that sets either builds on
  one kind of machine and fails on the other. The plain build keeps up with a
  station comfortably on anything that can run the rest of Skywave.

## What it does not change

Only HD Radio uses it. Television, the guide and recordings never touch nrsc5, and
if you skip the build entirely everything else works as before.
