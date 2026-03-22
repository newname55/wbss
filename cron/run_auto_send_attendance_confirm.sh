#!/usr/bin/env bash
set -euo pipefail

APP_ROOT="/var/www/html/wbss"
PHP_BIN="/usr/bin/php"

set -a
APACHE_CONFDIR="${APACHE_CONFDIR:-/etc/apache2}"
APACHE_ENVVARS="${APACHE_ENVVARS:-$APACHE_CONFDIR/envvars}"
set +u
. "$APACHE_ENVVARS"
set -u
set +a

DB_ENV_FILE="${WBSS_DB_ENV_FILE:-/etc/apache2/conf-enabled/wbss-db.conf}"
if [[ -f "$DB_ENV_FILE" ]]; then
  while read -r _ key value; do
    [[ "${key:-}" =~ ^(WBSS_DB_|SEIKA_DB_) ]] || continue
    if [[ "$key" =~ ^SEIKA_DB_ ]]; then
      key="WBSS_DB_${key#SEIKA_DB_}"
    fi
    value="${value%\"}"
    value="${value#\"}"
    export "$key=$value"
  done < <(grep -E '^[[:space:]]*SetEnv[[:space:]]+(WBSS_DB_|SEIKA_DB_)' "$DB_ENV_FILE")
fi

cd "$APP_ROOT"

$PHP_BIN "$APP_ROOT/cron/auto_send_attendance_confirm.php"
