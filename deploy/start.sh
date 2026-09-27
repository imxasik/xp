#!/bin/sh
# ওয়েব সার্ভার + পটভূমির কর্মী — দুটোই একসাথে
set -e
cd /app
echo "▶ BAF Weather Mirror — পোর্ট ${PORT}"

# পটভূমিতে ছবি আনার কর্মী
php /app/worker.php &
WORKER=$!

# একটা থামলে অন্যটাও থামবে (কনটেইনার রিস্টার্ট হবে)
trap 'kill $WORKER 2>/dev/null; exit 0' TERM INT

php -S "0.0.0.0:${PORT}" -t /app &
WEB=$!

wait -n $WORKER $WEB
kill $WORKER $WEB 2>/dev/null || true
