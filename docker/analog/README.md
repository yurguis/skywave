# Analog AM and FM

Two programs the web image does not carry, built here and mounted into it by the radio
overlay:

- **`rtlanalog`** — demodulates analog AM and FM from a dongle shared by `rtl_tcp`.
  Written for Skywave; the source is [`rtlanalog.c`](rtlanalog.c) beside this file.
- **`redsea`** — reads RDS out of the FM multiplex: the station's name, the song, the
  programme type. From [github.com/windytan/redsea](https://github.com/windytan/redsea),
  pinned to a commit in the Dockerfile.

```bash
docker/analog/build-analog.sh
docker compose up -d
```

The build takes a few minutes, most of it liquid-dsp, and writes `data/analog`. Use the
script rather than a bare `docker build -o`: a folder docker creates for itself is readable
by its owner alone, and the container, running as someone else, is then shown binaries it
cannot reach.

Nothing here is proprietary — unlike nrsc5, both of these could ship in the image. They are
built this way because the radio overlay already mounts one folder of built things, and one
way of doing it is easier to keep right than two.

## Why rtlanalog exists

`rtl_fm` already demodulates analog radio, and is the obvious answer. It cannot be used
here: it speaks only to a dongle it opens itself over USB, and Docker on a Mac cannot hand
it one. nrsc5 is the exception that makes HD Radio work at all — it has `-H host:port` and
reaches a dongle over the network. `rtlanalog` is that same idea for analog: an `rtl_tcp`
client that demodulates, so analog travels the path HD Radio already travels.

## What it puts out

Signed 16-bit mono on stdout, and nothing else. ffmpeg turns it into sound.

**FM comes out as the multiplex, not as audio**, at 171 kHz. This looks like the long way
round and is not: the multiplex is what both of its readers want. ffmpeg low-passes it to
15 kHz and de-emphasises it into sound; redsea takes the same bytes and reads the RDS
subcarrier at 57 kHz. Demodulating to audio here would throw the subcarrier away and leave
redsea nothing to read.

```bash
rtlanalog -H 192.168.1.20:1234 -M mpx -f 93100000 -g 40   # FM multiplex, 171 kHz
rtlanalog -H 192.168.1.20:1234 -M am  -f 1140000          # AM audio, 16 kHz
```

**AM needs the dongle in direct sampling**, which `rtlanalog` asks for itself. The R820T
tuner stops around 24 MHz and the broadcast band is far below it; direct sampling bypasses
the tuner and samples the ADC, which reaches the whole of HF. That only works on a dongle
wired for it — an RTL-SDR Blog V3 is, a plain DVB-T stick is not, and on one that is not
the band will sound empty rather than fail outright.

AM is sent out as the *depth* of the modulation rather than the strength of the signal, so
a distant station arrives as loud as a local one.

## What the build checks, and what it cannot

redsea has to read four RDS groups written out by hand and find the programme identifier in
them, with the development packages removed first — so a build that linked against
something it did not export fails there rather than on the air. redsea's own test signals
are no use for this: they live in git-lfs, and the shallow fetch brings down pointer files
rather than audio.

`rtlanalog` gets no such gate. It is one static file with no library to get wrong, and the
compiler has already refused it warnings. That it demodulates a real station is not
something a build container can show; it was checked against a dongle, and against
`tools/fake-rtl-tcp.php`, which serves a capture over the same protocol.
