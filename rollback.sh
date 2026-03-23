#!/bin/bash

set +u

cd /var/www/html/wbss || exit 1

ENV_FILE="/var/www/.wbss_env"
[ -f "$ENV_FILE" ] && source "$ENV_FILE"

LINE_TOKEN="${LINE_DEPLOY_TOKEN:-}"
LINE_USER="${LINE_DEPLOY_USER:-}"
APP_BASE_URL="${APP_BASE_URL:-https://haruto.asuscomm.com/wbss}"

HOSTNAME_SHORT="$(hostname)"
EXECUTED_BY="$(whoami)"
START="$(date '+%Y-%m-%d %H:%M:%S')"

# ===== 1つ前の成功commit取得 =====
TARGET_COMMIT=$(mysql -N -e "
SELECT before_commit
FROM deploy_logs
WHERE environment='dev_to_main'
AND status='success'
ORDER BY id DESC
LIMIT 1;
")

if [ -z "$TARGET_COMMIT" ]; then
  echo "rollback対象なし"
  exit 1
fi

echo "rollback → $TARGET_COMMIT"

# ===== rollback実行 =====
git fetch origin main
git reset --hard $TARGET_COMMIT

END="$(date '+%Y-%m-%d %H:%M:%S')"

MSG="⚠️ WBSS 本番rollback
host: $HOSTNAME_SHORT
by: $EXECUTED_BY
target: $TARGET_COMMIT
start: $START
end: $END
${APP_BASE_URL}/"

# ===== LINE =====
if [ -n "$LINE_TOKEN" ] && [ -n "$LINE_USER" ]; then
  curl -sS -X POST "https://api.line.me/v2/bot/message/push" \
    -H "Authorization: Bearer ${LINE_TOKEN}" \
    -H "Content-Type: application/json" \
    -d "{\"to\":\"${LINE_USER}\",\"messages\":[{\"type\":\"text\",\"text\":\"$MSG\"}]}"
fi

echo "rollback完了"