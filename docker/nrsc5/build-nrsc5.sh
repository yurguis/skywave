#!/bin/sh
#
# Builds nrsc5 for HD Radio and leaves it where the radio overlay looks for it.
#
#   docker/nrsc5/build-nrsc5.sh [output directory]
#
# The output directory is data/nrsc5 unless one is given; give one, and set NRSC5_DIR
# in .env to the same place, when data/ is not yours to write to. Docker creates that
# folder as root the first time it mounts the recordings directory, so on a machine
# that has already run Skywave it often is not.
#
# Exists because the obvious one-liner leaves a build nobody can use. Running
#
#   docker build -o data/nrsc5 -f docker/nrsc5/Dockerfile docker/nrsc5
#
# into a directory that does not exist yet has docker create it readable by its
# owner alone. The web container runs as another user, so it is shown a binary it
# cannot reach, and the page reports nrsc5 as not installed while it sits right
# there. A directory that already exists is left as it is, so this makes it first.

set -eu

root="$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)"
out="${1:-$root/data/nrsc5}"

if ! mkdir -p "$out" 2>/dev/null; then
    echo "cannot create $out" >&2
    echo "  $(dirname "$out") is probably owned by root: docker makes data/ itself when it" >&2
    echo "  first mounts the recordings folder. Either take it back," >&2
    echo "    sudo chown \"\$(id -u):\$(id -g)\" \"$(dirname "$out")\"" >&2
    echo "  or build somewhere else and name it in .env:" >&2
    echo "    $0 \"\$HOME/skywave-nrsc5\"   and   NRSC5_DIR=$HOME/skywave-nrsc5" >&2
    exit 1
fi

chmod 755 "$out"

echo "building nrsc5"
echo "  out: $out"

docker build -o "$out" -f "$root/docker/nrsc5/Dockerfile" "$root/docker/nrsc5"

echo
if [ -x "$out/bin/nrsc5" ]; then
    echo "build succeeded"
    ls -la "$out/bin/" "$out/lib/"
else
    echo "the build finished but exported no nrsc5, which is a failure in itself" >&2
    exit 1
fi
