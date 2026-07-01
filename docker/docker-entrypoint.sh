#!/bin/sh
set -e

cd /app

# ---------------------------------------------------------------------------
# Select the database provider.
#
# DB_PROVIDER switches the connection target without any code change — both are
# plain PostgreSQL, so the app stays 100% Postgres-compatible:
#   DB_PROVIDER=supabase -> uses $SUPABASE_DATABASE_URL  (Session pooler URL)
#   DB_PROVIDER=render   -> uses $RENDER_DATABASE_URL    (Render injects this)
#
# An explicitly-set DATABASE_URL (local dev, docker compose, .env) always wins
# and skips this selection entirely.
# ---------------------------------------------------------------------------
# Track which provider we actually select here. Left empty when DATABASE_URL
# was supplied explicitly, so we never rewrite a user-provided URL below.
_selected_provider=""
if [ -z "$DATABASE_URL" ]; then
	_selected_provider="${DB_PROVIDER:-render}"
	case "$_selected_provider" in
		supabase) export DATABASE_URL="$SUPABASE_DATABASE_URL" ;;
		render)   export DATABASE_URL="$RENDER_DATABASE_URL" ;;
		*)        echo "[entrypoint] ERROR: unknown DB_PROVIDER '${DB_PROVIDER}' (expected 'supabase' or 'render')."; exit 1 ;;
	esac

	if [ -z "$DATABASE_URL" ]; then
		echo "[entrypoint] ERROR: DB_PROVIDER='${_selected_provider}' selected but its connection URL is empty."
		echo "[entrypoint]        Set SUPABASE_DATABASE_URL / RENDER_DATABASE_URL, or set DATABASE_URL directly."
		exit 1
	fi
fi

# ---------------------------------------------------------------------------
# Normalize DATABASE_URL.
#  * Supabase requires TLS -> ensure sslmode=require. Applied ONLY when we
#    selected the Supabase provider here, never to a user-supplied URL (a local
#    non-SSL Postgres would break).
#  * Doctrine/DBAL needs a `serverVersion` to pick the platform without an extra
#    round-trip; append it if missing. Default 17 for Supabase, 16 otherwise
#    (both resolve to the modern PostgreSQL platform, so a mismatch is harmless).
# ---------------------------------------------------------------------------
if [ -n "$DATABASE_URL" ]; then
	if [ "$_selected_provider" = "supabase" ]; then
		case "$DATABASE_URL" in
			*sslmode=*) : ;; # already specified, leave untouched
			*\?*) export DATABASE_URL="${DATABASE_URL}&sslmode=require" ;;
			*)    export DATABASE_URL="${DATABASE_URL}?sslmode=require" ;;
		esac
	fi

	_default_sv=16
	[ "$_selected_provider" = "supabase" ] && _default_sv=17
	case "$DATABASE_URL" in
		*serverVersion=*) : ;; # already specified, leave untouched
		*\?*) export DATABASE_URL="${DATABASE_URL}&serverVersion=${DATABASE_SERVER_VERSION:-$_default_sv}&charset=utf8" ;;
		*)    export DATABASE_URL="${DATABASE_URL}?serverVersion=${DATABASE_SERVER_VERSION:-$_default_sv}&charset=utf8" ;;
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
