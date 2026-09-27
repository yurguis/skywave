#!/bin/sh
#
# Gives librempeg's OpenSSL backend a default trust store.
#
# Run from the top of the librempeg source tree during the build.
#
# Without this the binary cannot open the ATSC 3.0 broadband stations at all: exit 251,
# "certificate verify failed", against the same live 102.1 manifest the 6.1 build returns
# 0 on. libavformat/tls_openssl.c loads a trust store in exactly one place --
#
#     if (c->ca_file) { SSL_CTX_load_verify_locations(p->ctx, c->ca_file, NULL); }
#     ...
#     if (c->verify) SSL_CTX_set_verify(p->ctx, SSL_VERIFY_PEER|..., NULL);
#
# -- and SSL_CTX_set_default_verify_paths appears nowhere, while tls.h defaults
# tls_verify to { .i64 = 1 }. So verification is on against an empty store unless ca_file
# is passed. Harmless while that default was 0, which is why the 6.1 build is unaffected.
#
# The container is not at fault: curl and openssl s_client both verify the same host
# cleanly from inside it, the clock is right, and both binaries carry identical
# compiled-in trust paths.
#
# Passing -ca_file is not an alternative. It fixes only the outer connection; the DASH
# demuxer opens its segment fetches fresh and they inherit neither ca_file nor tls_verify,
# so they still fail with "Failed to open an initialization section". Every one of those
# connections comes through this same function, which is why the fix belongs here.
#
# GnuTLS would have been tidier -- its backend already falls back to
# gnutls_certificate_set_x509_system_trust() -- but alpine 3.24 ships libgnutls.so with no
# libgnutls.a, so it cannot satisfy --extra-ldflags=-static.
#
# An explicit ca_file still works afterwards: load_verify_locations adds to the store
# rather than replacing it.

set -eu

f=libavformat/tls_openssl.c
anchor='SSL_CTX_set_options(p->ctx, SSL_OP_NO_SSLv2'

if [ ! -f "$f" ]; then
    echo "  $f not found -- is this the librempeg source tree?"
    exit 1
fi

# Written as if/then rather than `grep -q ... && { ... }`: under `set -e` that form exits
# the script on the *normal* path, because the chain carries grep's non-zero status when
# the pattern is absent.
if grep -q 'set_default_verify_paths' "$f"; then
    echo "  $f already calls set_default_verify_paths -- upstream fixed this, drop the step"
    exit 1
fi

# Unique or nothing: a commit that moves this code must fail the build loudly rather than
# quietly yield a binary that cannot reach any station.
found=$(grep -c "$anchor" "$f" || true)
if [ "$found" != "1" ]; then
    echo "  patch anchor found $found times in $f, expected exactly 1"
    grep -n 'SSL_CTX_set_options' "$f" || true
    exit 1
fi

sed -i "/$anchor/a\\    SSL_CTX_set_default_verify_paths(p->ctx);" "$f"

if ! grep -q 'SSL_CTX_set_default_verify_paths(p->ctx);' "$f"; then
    echo "  the patch did not apply to $f"
    exit 1
fi

echo "  patched $f:"
grep -n -A2 "$anchor" "$f"
