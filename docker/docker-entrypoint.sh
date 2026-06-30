#!/bin/sh
set -e

cd /app

# ---------------------------------------------------------------------------
# Normalize DATABASE_URL.
# Render's managed PostgreSQL exposes a connection string WITHOUT a
# `serverVersion` query parameter, which Doctrine/DBAL needs to pick the right
# platform without an extra round-trip. Append it (and a charset) if missing.
# ---------------------------------------------------------------------------
if [ -n "$DATABASE_URL" ]; then
	case "$DATABASE_URL" in
		*serverVersion=*) : ;; # already specified, leave untouched
		*\?*) export DATABASE_URL="${DATABASE_URL}&serverVersion=${DATABASE_SERVER_VERSION:-16}&charset=utf8" ;;
		*)    export DATABASE_URL="${DATABASE_URL}?serverVersion=${DATABASE_SERVER_VERSION:-16}&charset=utf8" ;;
	esac
fi

# ---------------------------------------------------------------------------
# Generate the JWT key pair if it does not already exist.
# Tip: mount a Render persistent disk (or supply the keys via secret files) at
# /app/config/jwt to keep keys stable across deploys; otherwise tokens issued
# before a redeploy are invalidated.
# ---------------------------------------------------------------------------
if [ ! -f config/jwt/private.pem ] || [ ! -f config/jwt/public.pem ]; then
	echo "[entrypoint] Generating JWT key pair..."
	php bin/console lexik:jwt:generate-keypair --skip-if-exists --no-interaction
fi

# ---------------------------------------------------------------------------
# Wait for the database to accept connections before running migrations.
# ---------------------------------------------------------------------------
if [ "${RUN_MIGRATIONS:-true}" = "true" ] && [ -n "$DATABASE_URL" ]; then
	echo "[entrypoint] Waiting for database..."
	i=0
	until php bin/console dbal:run-sql "SELECT 1" >/dev/null 2>&1; do
		i=$((i + 1))
		if [ "$i" -ge 30 ]; then
			echo "[entrypoint] Database not reachable after 60s, attempting migration anyway."
			break
		fi
		sleep 2
	done

	echo "[entrypoint] Running database migrations..."
	php bin/console doctrine:migrations:migrate --no-interaction --all-or-nothing --allow-no-migration
fi

# ---------------------------------------------------------------------------
# Optional one-off seeding. Disabled by default because the seeder creates a
# fixed admin user (unique email) and would fail on a second run.
# Set RUN_SEED=true for the very first deploy only, then unset it.
# ---------------------------------------------------------------------------
if [ "${RUN_SEED:-false}" = "true" ]; then
	echo "[entrypoint] Seeding database..."
	php bin/console app:seed-data || echo "[entrypoint] Seeding skipped/failed (data may already exist)."
fi

exec "$@"
