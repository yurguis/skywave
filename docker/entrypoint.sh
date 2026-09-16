#!/bin/sh
# Container entrypoint for the web UI: writes the nginx config and password file,
# then runs php-fpm and nginx and stops the container if either one exits.
#
# Any arguments run that command instead (e.g. the simulator):
#   docker run skywave php tools/fake-hdhomerun.php --capture=/captures/capture.ts
set -eu

if [ "$#" -gt 0 ]; then
    exec "$@"
fi

RUNTIME_DIR=/tmp/hdhomerun-web
HTTP_PORT="${HTTP_PORT:-8080}"
AUTH_USER="${AUTH_USER:-admin}"

case "$HTTP_PORT" in
    '' | *[!0-9]*) echo "HTTP_PORT must be a port number, got: $HTTP_PORT" >&2; exit 1 ;;
esac

mkdir -p "$RUNTIME_DIR"

if [ -n "${AUTH_PASSWORD_FILE:-}" ]; then
    AUTH_PASSWORD="$(cat "$AUTH_PASSWORD_FILE")"
fi

if [ -n "${AUTH_PASSWORD:-}" ]; then
    # SHA-512 crypt, which nginx verifies through the C library. The password is passed
    # in the environment rather than as an argument so it never shows up in `ps`.
    AUTH_USER="$AUTH_USER" AUTH_PASSWORD="$AUTH_PASSWORD" php -r '
        $salt = "\$6\$" . bin2hex(random_bytes(8)) . "\$";
        file_put_contents($argv[1], getenv("AUTH_USER") . ":" . crypt(getenv("AUTH_PASSWORD"), $salt) . "\n");
    ' "$RUNTIME_DIR/htpasswd"
    AUTH_DIRECTIVES="auth_basic \"Skywave\"; auth_basic_user_file $RUNTIME_DIR/htpasswd;"
elif [ "${AUTH_DISABLED:-}" = "1" ]; then
    AUTH_DIRECTIVES="auth_basic off;"
    echo "WARNING: AUTH_DISABLED=1, anyone who can reach port $HTTP_PORT can control your tuners." >&2
else
    echo "Set AUTH_PASSWORD (or AUTH_PASSWORD_FILE) to protect the UI, or AUTH_DISABLED=1 to run without a password." >&2
    exit 1
fi

# php-fpm keeps the environment for PHP (HDHOMERUN_DEVICES); the password must not be in it.
unset AUTH_PASSWORD AUTH_PASSWORD_FILE

sed -e "s|@HTTP_PORT@|$HTTP_PORT|g" \
    -e "s|@RUNTIME_DIR@|$RUNTIME_DIR|g" \
    -e "s|@AUTH_DIRECTIVES@|$AUTH_DIRECTIVES|" \
    /etc/hdhomerun/nginx.conf.template > "$RUNTIME_DIR/nginx.conf"

php-fpm --nodaemonize &
FPM_PID=$!

# Start nginx once php-fpm's socket exists, so early requests do not fail.
tries=0
while [ ! -S "$RUNTIME_DIR/php-fpm.sock" ] && [ "$tries" -lt 50 ] && kill -0 "$FPM_PID" 2>/dev/null; do
    sleep 0.1
    tries=$((tries + 1))
done

nginx -e stderr -c "$RUNTIME_DIR/nginx.conf" -g 'daemon off;' &
NGINX_PID=$!

stop() {
    kill -TERM "$NGINX_PID" "$FPM_PID" 2>/dev/null || true
    wait "$NGINX_PID" "$FPM_PID" 2>/dev/null || true
}

trap 'stop; exit 0' TERM INT QUIT

echo "Skywave listening on port $HTTP_PORT"

set +e
while kill -0 "$FPM_PID" 2>/dev/null && kill -0 "$NGINX_PID" 2>/dev/null; do
    # Sleeping in the background keeps the shell responsive to TERM.
    sleep 2 &
    wait $!
done

echo "php-fpm or nginx exited; stopping the container." >&2
stop
exit 1
