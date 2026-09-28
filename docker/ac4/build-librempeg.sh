#!/bin/sh
#
# Builds the AC-4 decoder and reports honestly whether it worked.
#
#   docker/ac4/build-librempeg.sh [commit]
#
# Exists because the obvious one-liner lies. Running
#
#   docker build ... 2>&1 | tail -80
#
# reports tail's exit status, not docker's, so a build that failed at `make` comes
# back as exit code 0 and is believed. The pipe also buffers, so there is no interim
# output to watch while it runs -- the worst of both.
#
# So: pipefail, the whole log kept on disk rather than truncated to its last lines,
# and the script's status is the build's status.
#
# Plain `docker build -o data/ac4 -f docker/ac4/Dockerfile docker/ac4` works too; this
# only adds the honest reporting.

set -eu

commit="${1:-0ada1735571528a47634d19d213d28eb49e01285}"
root="$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)"
log="$root/data/ac4-build.log"

mkdir -p "$root/data"

echo "building librempeg $commit"
echo "  log: $log"
echo "  out: $root/data/ac4"

# pipefail is the point of this script: without it the tee below would mask a failing
# build exactly as tail did.
set -o pipefail 2>/dev/null || true

# Not named "status": that is read-only in zsh, so the assignment itself fails and its
# non-zero result becomes the script's exit code -- a successful build reported as a
# failure. The shebang means this runs under sh, where the name is fine, but the trap
# is real for anyone who runs it another way and it costs nothing to avoid.
if docker build \
        --progress=plain \
        --build-arg "LIBREMPEG_COMMIT=$commit" \
        -o "$root/data/ac4" \
        -f "$root/docker/ac4/Dockerfile" \
        "$root/docker/ac4" 2>&1 | tee "$log"; then
    build_status=0
else
    build_status=$?
fi

echo
if [ "$build_status" -eq 0 ]; then
    echo "build succeeded"
    ls -la "$root/data/ac4/bin/" 2>&1 || echo "  but nothing was exported, which is a failure in itself"
    grep -E '^#[0-9]+ [0-9.]+ License:' "$log" | tail -1 || true
else
    echo "build FAILED (exit $build_status)"
    echo "the error, in context:"
    grep -n -B4 -A2 'fatal error\|Error 1\|ERROR:' "$log" | tail -40
fi

exit "$build_status"
