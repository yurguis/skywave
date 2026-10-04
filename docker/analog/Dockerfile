# syntax=docker/dockerfile:1

# Builds the two programs analog radio needs and exports them to the host:
#
#   rtlanalog   demodulates AM and FM from a dongle shared by rtl_tcp. Written here;
#               the source sits beside this file.
#   redsea      reads RDS out of the FM multiplex: the station's name and the song.
#
#   docker/analog/build-analog.sh
#
# which runs "docker build -o data/analog -f docker/analog/Dockerfile docker/analog" and
# makes sure the result can be read. Produces data/analog/bin and data/analog/lib.
#
# Unlike nrsc5 there is nothing proprietary here -- both are open and could be shipped in
# the image. They are built this way because the radio overlay already mounts one folder
# of built things, and one way of doing it is easier to keep right than two.

# The same Alpine the web image is built on: these are linked against this release's musl
# and run against the web container's, so the two have to agree.
FROM alpine:3.24 AS build

# Pinned rather than tracking master, so this build is repeatable and a change upstream
# cannot quietly become part of the result.
ARG REDSEA_REPO=https://github.com/windytan/redsea.git
ARG REDSEA_COMMIT=7555c9f6259d50718697ee8c9f218ea012c6892c
ARG LIQUID_REPO=https://github.com/jgaeddert/liquid-dsp.git
ARG LIQUID_COMMIT=6470d5cdba3d2713e452a96109fe07fa970cad90

# samurai is Alpine's ninja. autoconf, automake and libtool are not for redsea: they are
# for liquid-dsp, which Alpine does not package and which has to be built below.
RUN apk add --no-cache \
        build-base git \
        meson samurai \
        autoconf automake libtool \
        libsndfile-dev nlohmann-json

# rtlanalog first: it is ours, it is one file, and it has nothing to download. Static,
# because it needs only libc and libm and a binary that needs no libraries cannot be
# given the wrong ones.
WORKDIR /src/rtlanalog
COPY rtlanalog.c .
RUN mkdir -p /opt/analog/bin \
    && gcc -O2 -Wall -Wextra -Werror -static -o /opt/analog/bin/rtlanalog rtlanalog.c -lm \
    && strip /opt/analog/bin/rtlanalog \
    && /opt/analog/bin/rtlanalog -h 2>&1 | head -1

# liquid-dsp, which redsea needs. Its meson file asks for the library without requiring
# it, which reads as optional and is not: the sources include liquid/liquid.h whatever the
# build decided, so a redsea built without it fails at the first compile. Built here, from
# the release the recipe was written against.
WORKDIR /src/liquid
RUN git init -q . \
    && git remote add origin "$LIQUID_REPO" \
    && git fetch -q --depth 1 origin "$LIQUID_COMMIT" \
    && git checkout -q FETCH_HEAD \
    && git log -1 --format='  building liquid-dsp %H (%ad)' --date=short \
    && ./bootstrap.sh >/dev/null 2>&1 \
    && ./configure --prefix=/usr >/dev/null \
    && make -j"$(nproc)" >/dev/null \
    && make install >/dev/null \
    && ls -la /usr/lib/libliquid*

WORKDIR /src/redsea

# Fetching the one commit rather than cloning the history: none of it is wanted. The test
# signals it would bring are kept in git-lfs and arrive as pointer files, which is why the
# check at the end does not use them.
RUN git init -q . \
    && git remote add origin "$REDSEA_REPO" \
    && git fetch -q --depth 1 origin "$REDSEA_COMMIT" \
    && git checkout -q FETCH_HEAD \
    && git log -1 --format='  building redsea %H (%ad)' --date=short

# Told where its libraries will be -- /opt/analog/lib, which is where the overlay mounts
# what this exports -- and they are exported with it, the way nrsc5 is.
RUN meson setup build --buildtype=release \
        -Dcpp_link_args='-Wl,-rpath,/opt/analog/lib' \
    && ninja -C build \
    && mkdir -p /opt/analog/lib \
    && cp build/redsea /opt/analog/bin/ \
    && strip /opt/analog/bin/redsea \
    && ldd build/redsea \
        | awk '$3 ~ /^\/usr\/lib\// { print $3 }' \
        | xargs -r -I{} cp -L {} /opt/analog/lib/ \
    && ls -la /opt/analog/bin /opt/analog/lib

# Fail here rather than exporting something that cannot read a station. redsea's own test
# signals are no use for this: they are kept in git-lfs, and a shallow fetch brings down
# pointer files rather than audio. So it is given four RDS groups written out by hand, with
# a known programme identifier in them, and has to find it. That exercises the decoder and
# the libraries exported beside it -- the development packages are removed first, so a
# build that linked against something it did not export fails here instead of on the air.
#
# rtlanalog gets no such gate. It is one static file with no library to get wrong, and the
# compiler has already refused it warnings; what it cannot prove by itself is that it
# demodulates a real station, and nothing in a build container can.
RUN apk del --no-cache libsndfile-dev >/dev/null \
    && printf '625D 0000 E0E0 5746\n625D 0001 E0E0 455A\n625D 0002 E0E0 2020\n625D 0003 E0E0 2020\n' \
        | /opt/analog/bin/redsea -i hex > /tmp/rds.json 2>/dev/null \
    ; grep -q '"pi":"0x625D"' /tmp/rds.json \
    && printf '  redsea read the test groups back: %s\n' "$(head -1 /tmp/rds.json | cut -c1-60)"

FROM scratch AS export
COPY --from=build /opt/analog/bin /bin
COPY --from=build /opt/analog/lib /lib
