# syntax=docker/dockerfile:1

# Builds ffmpeg with the community AC-4 decoder patch and exports the two
# binaries to the host. Read docker/ac4/README.md before building: the result
# is not redistributable, and this file is the recipe, never the product.
#
#   docker build -o data/ac4 -f docker/ac4/Dockerfile docker/ac4
#
# Produces data/ac4/bin/ffmpeg and data/ac4/bin/ffprobe.

FROM alpine:3.24 AS build

# The patch is maintained against 6.1 and does not apply to 7.x or 8.x.
ARG FFMPEG_VERSION=6.1
ARG PATCH_URL=https://raw.githubusercontent.com/funnymanva/ffmpeg-with-ac4/main/ffmpeg_ac4.patch
# Pinned so that a patch changed upstream fails the build instead of being
# compiled in unnoticed.
ARG PATCH_SHA256=87725d61833e9dca26bf9581a65b4062f0364e08299c7ce069c4c742624609a0

# libxml2 is not optional here: ffmpeg only builds its DASH demuxer when
# libxml2 is present, and these stations are played from a DASH manifest. A
# build without it reports "ac4" among its decoders and still cannot open the
# stream at all.
RUN apk add --no-cache \
        build-base pkgconf coreutils nasm curl \
        x264-dev \
        libxml2-dev libxml2-static \
        openssl-dev openssl-libs-static \
        zlib-static bzip2-static xz-static

WORKDIR /src

RUN curl -fsSLO "https://ffmpeg.org/releases/ffmpeg-${FFMPEG_VERSION}.tar.xz" \
    && tar xf "ffmpeg-${FFMPEG_VERSION}.tar.xz"

RUN curl -fsSL -o ac4.patch "$PATCH_URL" \
    && echo "${PATCH_SHA256}  ac4.patch" | sha256sum -c -

WORKDIR /src/ffmpeg-${FFMPEG_VERSION}
RUN patch -p1 < ../ac4.patch

# Static against musl deliberately. These stations stream from a CDN, so the
# binary has to resolve DNS; musl does that without NSS, while a static glibc
# build cannot and would fail on every lookup.
RUN ./configure \
        --prefix=/opt/ac4 \
        --pkg-config-flags=--static \
        --extra-ldflags=-static \
        --extra-libs="-lpthread -lm" \
        --enable-gpl --enable-nonfree \
        --enable-libx264 --enable-openssl --enable-libxml2 \
        --disable-doc --disable-ffplay \
        --disable-shared --enable-static \
    && make -j"$(nproc)" \
    && make install

# Fail here rather than shipping a binary that silently lacks either half of
# what playback needs: the decoder, and the demuxer that reaches the stream.
RUN /opt/ac4/bin/ffmpeg -hide_banner -decoders | grep -E '(^| )ac4( |$)' \
    && /opt/ac4/bin/ffmpeg -hide_banner -demuxers | grep -E '^[[:space:]]*D[[:space:]]+dash([[:space:]]|$)'

FROM scratch AS export
COPY --from=build /opt/ac4/bin/ffmpeg /opt/ac4/bin/ffprobe /bin/
