#!/bin/sh
set -e

cd /app

if [ ! -f .env ]; then
	echo "[entrypoint] .env ausente, lo genero desde .env.example"
	cp .env.example .env
	php artisan key:generate --force
fi

if [ ! -d vendor ]; then
	echo "[entrypoint] vendor/ ausente, corriendo composer install"
	composer install --prefer-dist --no-interaction --no-progress
fi

mkdir -p database \
	storage/framework/cache \
	storage/framework/sessions \
	storage/framework/views \
	storage/logs \
	bootstrap/cache

[ -f database/database.sqlite ] || touch database/database.sqlite

php artisan config:clear >/dev/null 2>&1 || true
php artisan route:clear >/dev/null 2>&1 || true

REDIS_WAIT_HOST="${REDIS_HOST:-redis}"
REDIS_WAIT_PORT="${REDIS_PORT:-6379}"
echo "[entrypoint] esperando redis en ${REDIS_WAIT_HOST}:${REDIS_WAIT_PORT}"
until php -r 'exit(@fsockopen($argv[1], (int) $argv[2], $e, $m, 1) ? 0 : 1);' "$REDIS_WAIT_HOST" "$REDIS_WAIT_PORT"; do
	sleep 1
done

php artisan migrate --force --graceful
php artisan db:seed --force

# Tablas del driver 'apigateway' en DynamoDB Local. En AWS las crea SAM y
# DYNAMODB_ENDPOINT va vacio, asi que esto no corre.
if [ -n "${DYNAMODB_ENDPOINT}" ]; then
	DYNAMO_HOSTPORT="${DYNAMODB_ENDPOINT#*://}"
	echo "[entrypoint] esperando DynamoDB en ${DYNAMO_HOSTPORT}"
	until php -r 'exit(@fsockopen($argv[1], (int) $argv[2], $e, $m, 1) ? 0 : 1);' "${DYNAMO_HOSTPORT%:*}" "${DYNAMO_HOSTPORT##*:}"; do
		sleep 1
	done
	php artisan ws:create-tables
fi

echo "[entrypoint] API lista en http://localhost:8000"

exec "$@"
