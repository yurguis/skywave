# syntax=docker/dockerfile:1

# Builds librempeg and exports its two binaries to the host. Read
# docker/ac4/README.md before building: AC-4 is patented, and this file is the
# recipe, never the product.
#
#   docker build -o data/ac4 -f docker/ac4/Dockerfile docker/ac4
#
# Produces data/ac4/bin/ffmpeg and data/ac4/bin/ffprobe.
#
# Why librempeg rather than the 2020 AC-4 patch this recipe used to apply: it is
# Paul B Mahol's own fork, and libavcodec/ac4dec.c there is the maintained
# descendant of that very patch -- same author, same filename, but on an 8.x base
# with six years of fixes the RFC snapshot predates. The old recipe pinned ffmpeg
# 6.1 because the patch applied to nothing newer. Judged by ear on 102.1, this one
# is not merely equivalent: the intermittent hiccups that survived the audio-sync
# work in LiveStreams are gone.

FROM alpine:3.24 AS build

# Pinned rather than tracking master, so this build is repeatable and a change
# upstream cannot quietly become part of the result.
#
# Not master's tip, deliberately. Commit ac8be5e (2026-09-25) "remove libswresample
# bits" deleted libswresample/ while fftools/cmdutils.c, ffprobe.c and opt_common.c
# still include libswresample/swresample.h, so the tip cannot build its own
# command-line tools. This is the commit immediately before that.
ARG LIBREMPEG_REPO=https://github.com/librempeg/librempeg.git
ARG LIBREMPEG_COMMIT=0ada1735571528a47634d19d213d28eb49e01285

# libxml2 is not optional: ffmpeg and its forks only build the DASH demuxer when
# libxml2 is present, and these stations are played from a DASH manifest. A build
# without it lists "ac4" among its decoders and still cannot open the stream at all.
#
# x264-dev, not x264-static: the latter does not exist in alpine, and x264-dev is
# what ships the .a a static build needs.
RUN apk add --no-cache \
        build-base pkgconf coreutils nasm curl git \
        x264-dev \
        libxml2-dev libxml2-static \
        openssl-dev openssl-libs-static \
        zlib-static bzip2-static xz-static

WORKDIR /src/librempeg

# Fetching the one commit rather than cloning the history: this tree is large and
# none of it is wanted.
RUN git init -q . \
    && git remote add origin "$LIBREMPEG_REPO" \
    && git fetch -q --depth 1 origin "$LIBREMPEG_COMMIT" \
    && git checkout -q FETCH_HEAD \
    && git log -1 --format='  building librempeg %H (%ad)' --date=short

# Confirm the decoder is actually in this tree before spending a build on it.
RUN test -f libavcodec/ac4dec.c \
    && printf '  ac4dec.c present: %s lines\n' "$(wc -l < libavcodec/ac4dec.c)"

# Give the OpenSSL backend a trust store. Without this the binary cannot open any
# of these stations at all: exit 251, "certificate verify failed", against the same
# live manifest the old 6.1 build returned 0 on.
#
# libavformat/tls_openssl.c loads a trust store in exactly one place --
# `if (c->ca_file) SSL_CTX_load_verify_locations(...)` -- and
# SSL_CTX_set_default_verify_paths appears nowhere in the file, while tls.h defaults
# tls_verify to { .i64 = 1 }. So it verifies against an empty store unless ca_file is
# passed. Harmless while that default was 0, which is exactly why 6.1 was unaffected.
#
# Passing -ca_file is not an alternative: it fixes only the outer connection. The
# DASH demuxer opens its segment fetches fresh and they inherit neither ca_file nor
# tls_verify, so they still fail with "Failed to open an initialization section".
# Every one of those connections comes through this same function, which is why the
# fix belongs there and reaches all of them at once.
#
# Kept in its own script rather than inline: a RUN line passes through the Dockerfile
# parser and then the shell, and the backslashes an in-place insertion needs get
# fought over by both.
COPY patch-librempeg-tls.sh /usr/local/bin/patch-librempeg-tls
RUN sh /usr/local/bin/patch-librempeg-tls

# Static against musl deliberately. These stations stream from a CDN, so the binary
# has to resolve DNS; musl does that without NSS, while a static glibc build cannot
# and would fail on every lookup.
#
# --enable-agpl is not optional, and leaving it out is a silent trap. librempeg gates
# the main program on it --
#
#     ffmpeg_deps="avcodec avfilter avformat threads agpl"
#
# -- where upstream ffmpeg attaches no licence dependency to it at all. Without the
# flag, configure drops ffmpeg from its program list without a word, make succeeds,
# and the install directory holds ffprobe alone. That cost a whole build to notice,
# which is why the check below exists.
#
# --enable-nonfree is required because OpenSSL >= 3.0.0 demands either it or
# --enable-version3 alongside --enable-gpl, and version3 does not work here.
#
# Do not "fix" this by swapping the flag. It was tried (2026-09-27): configure
# accepts it and reports "License: AGPL version 3 or later", but the build then dies
# with
#
#     fftools/ffmpeg.h:50: fatal error: libswresample/swresample.h: No such file
#
# because version3 leaves configure building only avcodec, avfilter and avutil --
# swresample, avformat, avdevice and swscale are dropped, and fftools cannot compile
# against headers that were never generated. Configure succeeding proves nothing;
# only `make` does.
#
# The practical cost is nil. nonfree makes the result "nonfree and unredistributable",
# but AC-4 is patented and Dolby licenses it, so the binary could not be redistributed
# under any licence string. See README.md: the licence is the lesser half of that
# question and the patent half is unchanged either way.
RUN ./configure \
        --prefix=/opt/ac4 \
        --pkg-config-flags=--static \
        --extra-ldflags=-static \
        --extra-libs="-lpthread -lm" \
        --enable-gpl --enable-agpl --enable-nonfree \
        --enable-libx264 --enable-openssl --enable-libxml2 \
        --disable-doc --disable-ffplay \
        --disable-shared --enable-static

# Configure records what it decided, so check it now rather than after two minutes
# of compiling. A program dropped for an unmet dependency is never announced -- that
# is the whole reason this step exists -- and the ffmpeg binary is the point.
RUN grep -q '^CONFIG_FFMPEG=yes' ffbuild/config.mak \
    || { echo 'configure dropped the ffmpeg program. What it decided:'; \
         grep -E '^!?CONFIG_(FFMPEG|FFPROBE|FFPLAY|AGPL|GPL|NONFREE|VERSION3)=' ffbuild/config.mak; \
         exit 1; }

RUN make -j"$(nproc)" \
    && make install

# Listed before the assertions below: a fork is free to rename what it installs, and
# seeing the directory is quicker to read than a grep that found nothing.
RUN ls -la /opt/ac4/bin/

# Fail here rather than exporting a binary that silently lacks either half of what
# playback needs: the decoder, and the demuxer that reaches the stream.
RUN /opt/ac4/bin/ffmpeg -hide_banner -decoders | grep -E '(^| )ac4( |$)' \
    && /opt/ac4/bin/ffmpeg -hide_banner -demuxers | grep -E '^[[:space:]]*D[[:space:]]+dash([[:space:]]|$)'

FROM scratch AS export
COPY --from=build /opt/ac4/bin/ffmpeg /opt/ac4/bin/ffprobe /bin/
