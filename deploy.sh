#!/usr/bin/env bash
#
# One-shot deployment for this EspoCRM instance.
#
#   ./deploy.sh                              run the full sequence
#   ./deploy.sh --subject=mailto:you@host    VAPID contact subject for new keys
#   ./deploy.sh --url=https://host/app       verify HTTPS, sw.js and /data/ blocking
#   ./deploy.sh --skip-rebuild               metadata was already rebuilt
#
# It cannot create the two things that belong to the server: an HTTPS
# certificate and a cron entry. Both are printed at the end.

set -euo pipefail

cd "$(dirname "$0")"

PHP="${PHP:-php}"
SUBJECT=""
URL=""
DO_REBUILD=1
EXIT=0

for arg in "$@"; do
    case "$arg" in
        --subject=*)     SUBJECT="${arg#*=}" ;;
        --url=*)         URL="${arg#*=}" ;;
        --skip-rebuild)  DO_REBUILD=0 ;;
        -h|--help)       sed -n '2,11p' "$0"; exit 0 ;;
        *) printf 'unknown option: %s (try --help)\n' "$arg" >&2; exit 2 ;;
    esac
done

step() { printf '\n%s\n' "== $1"; }
ok()   { printf '   OK    %s\n' "$1"; }
warn() { printf '   WARN  %s\n' "$1"; }
fail() { printf '   FAIL  %s\n' "$1" >&2; EXIT=1; }

step "1/7  PHP"
if ! command -v "$PHP" >/dev/null 2>&1; then
    printf '   FAIL  %s not found - set PHP=/path/to/php\n' "$PHP" >&2
    exit 1
fi
PHPV="$("$PHP" -v 2>/dev/null || true)"
ok "${PHPV%%$'\n'*}"

step "2/7  metadata"
if [ "$DO_REBUILD" = 1 ]; then
    if "$PHP" rebuild.php >/dev/null && "$PHP" clear_cache.php >/dev/null; then
        ok "rebuild.php + clear_cache.php"
    else
        fail "rebuild or clear_cache reported an error"
    fi
else
    ok "skipped (--skip-rebuild)"
fi

step "3/7  service worker"
if [ -f public/sw.js ]; then
    ok "public/sw.js present (must be served at <base>/sw.js)"
else
    fail "public/sw.js is missing"
fi

step "4/7  VAPID keys"
if [ -n "$SUBJECT" ]; then
    case "$SUBJECT" in
        mailto:*|https://*) : ;;
        *) fail "subject must start with mailto: or https://" ;;
    esac
fi

if [ -f data/webpush-config.php ]; then
    ok "data/webpush-config.php present"
else
    CMD="$PHP command.php web-push-generate-vapid-keys"
    if [ -n "$SUBJECT" ]; then
        CMD="$CMD --subject=$SUBJECT"
    fi
    if sh -c "$CMD"; then
        ok "generated data/webpush-config.php"
    else
        warn "could not generate from here - the app will generate it on first use"
    fi
fi
if [ -n "$SUBJECT" ]; then
    ok "contact subject: $SUBJECT"
fi

step "5/7  master switch"
if grep -Eq "['\"]webPushEnabled['\"][[:space:]]*=>[[:space:]]*false" data/config.php 2>/dev/null; then
    fail "webPushEnabled is false in data/config.php - push is switched off"
else
    ok "webPushEnabled is not disabled"
fi

step "6/7  /data/ must not be served"
if [ -f .htaccess ] && grep -q 'data/ - \[F\]' .htaccess; then
    ok "apache: .htaccess returns 403 for /data/"
else
    warn "no apache rule found - nginx needs: location ^~ /data { deny all; }"
fi

step "7/7  remote checks"
if [ -z "$URL" ]; then
    ok "skipped - pass --url=https://host/app to verify the live site"
else
    case "$URL" in
        https://*) ok "https" ;;
        *)         fail "$URL is not https - browsers block push on plain http" ;;
    esac

    if curl -fsSIL --max-time 20 "$URL/sw.js" >/dev/null 2>&1; then
        ok "sw.js answers at $URL/sw.js"
    else
        fail "sw.js not reachable at $URL/sw.js"
    fi

    if curl -fsSIL --max-time 20 "$URL/data/webpush-config.php" >/dev/null 2>&1; then
        fail "data/webpush-config.php is downloadable - block /data/ immediately"
    else
        ok "data/ is not web-reachable"
    fi
fi

ROOT="$(pwd)"
cat <<EOF

------------------------------------------------------------------
Still required on the server - the app cannot create these itself:

  1. HTTPS certificate (cPanel / Let's Encrypt auto-SSL is fine).
     Without it the browser never registers the service worker.

  2. Cron, every minute - this is what fires the call reminders:

       * * * * * cd $ROOT && $PHP cron.php > /dev/null 2>&1

     No shell access? Point an external cron service at
     https://your-host/cron.php every minute instead.

  3. After moving from localhost to this domain, every browser must
     click the Web Push toggle off, then on, once - subscriptions are
     tied to the origin and do not survive the move.
------------------------------------------------------------------
EOF

exit $EXIT
