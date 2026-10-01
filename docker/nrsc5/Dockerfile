# syntax=docker/dockerfile:1

# Builds nrsc5, the HD Radio decoder, and exports it to the host. Read
# docker/nrsc5/README.md before building: HD Radio's audio codec is proprietary,
# and this file is the recipe, never the product.
#
#   docker/nrsc5/build-nrsc5.sh
#
# which runs "docker build -o data/nrsc5 -f docker/nrsc5/Dockerfile docker/nrsc5"
# and makes sure the result can be read. Produces data/nrsc5/bin/nrsc5 and, beside
# it in data/nrsc5/lib, the shared libraries it needs.

# The same Alpine the web image is built on. The binary is linked against this
# release's musl and run against the web container's, so the two have to agree.
FROM alpine:3.24 AS build

# Pinned rather than tracking master, so this build is repeatable and a change
# upstream cannot quietly become part of the result.
ARG NRSC5_REPO=https://github.com/theori-io/nrsc5.git
ARG NRSC5_COMMIT=9beb2c77171472c707f285f51a1385ac4c4a8be6

# autoconf, automake, libtool and patch are not for nrsc5 itself: its build
# downloads faad2, patches it to understand HD Radio's variant of AAC, and
# compiles that. xz is only for the check at the end.
RUN apk add --no-cache \
        build-base cmake pkgconf git patch xz \
        autoconf automake libtool \
        fftw-dev libao-dev librtlsdr-dev libusb-dev

WORKDIR /src/nrsc5

# Fetching the one commit rather than cloning the history: none of it is wanted.
RUN git init -q . \
    && git remote add origin "$NRSC5_REPO" \
    && git fetch -q --depth 1 origin "$NRSC5_COMMIT" \
    && git checkout -q FETCH_HEAD \
    && git log -1 --format='  building nrsc5 %H (%ad)' --date=short

# Not static, because it cannot be: nrsc5 writes its sound through libao, which
# finds its drivers at run time and has no static form on Linux. So the binary is
# told where its libraries will be instead -- /opt/nrsc5/lib, which is where the
# overlay mounts what this exports -- and they are exported with it.
#
# No -DUSE_SSE or -DUSE_NEON: they are worth having, but a recipe that sets them
# builds on one kind of machine and fails on the other.
RUN cmake -B build \
        -DCMAKE_BUILD_TYPE=Release \
        -DCMAKE_EXE_LINKER_FLAGS='-Wl,-rpath,/opt/nrsc5/lib' \
    && cmake --build build -j"$(nproc)"

# Everything it links against apart from musl itself, which the web image has.
RUN mkdir -p /opt/nrsc5/bin /opt/nrsc5/lib \
    && cp build/src/nrsc5 /opt/nrsc5/bin/ \
    && strip /opt/nrsc5/bin/nrsc5 \
    && ldd build/src/nrsc5 \
        | awk '$3 ~ /^\/usr\/lib\// { print $3 }' \
        | xargs -r -I{} cp -L {} /opt/nrsc5/lib/ \
    && ls -la /opt/nrsc5/bin /opt/nrsc5/lib

# Fail here rather than exporting a binary that cannot decode a station. nrsc5
# ships a capture of one; decoding it exercises the whole chain except the dongle,
# and a build whose patched faad2 went wrong still starts, still synchronizes and
# writes no sound at all. The libraries are removed from where the linker would
# otherwise find them, so this also proves the exported copies are the ones used.
RUN apk del --no-cache fftw-dev libao-dev librtlsdr-dev libusb-dev >/dev/null \
    && xz -d < support/sample.xz \
        | /opt/nrsc5/bin/nrsc5 -r - -o /tmp/sample.raw -t raw 0 2> /tmp/sample.log \
    ; grep -m1 'Station name' /tmp/sample.log \
    && test "$(wc -c < /tmp/sample.raw)" -gt 1000000 \
    && printf '  decoded the sample capture: %s bytes of sound\n' "$(wc -c < /tmp/sample.raw)"

FROM scratch AS export
COPY --from=build /opt/nrsc5/bin /bin
COPY --from=build /opt/nrsc5/lib /lib
