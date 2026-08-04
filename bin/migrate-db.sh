#!/bin/bash
# =============================================================================
# migrate-db.sh — copy one PostgreSQL database into another (schema + data).
#
# Built for the Render -> Aiven move, but source/target are just URLs, so it
# also works for Supabase -> Aiven or any plain-Postgres pair.
#
#   SOURCE_DATABASE_URL="postgresql://user:pass@host/db" \
#   TARGET_DATABASE_URL="postgres://avnadmin:pass@host:port/defaultdb?sslmode=require" \
#   bin/migrate-db.sh
#
# or: bin/migrate-db.sh <source-url> <target-url>
#
# Options (env vars):
#   CLEAN=1        drop existing objects in the target before restoring
#                  (pg_restore --clean --if-exists). Use for re-runs.
#   DUMP_ONLY=1    only produce the dump file, skip the restore.
#   PG_BIN=/path   directory holding pg_dump/pg_restore/psql if not on PATH.
#
# The dump is kept in var/export/ (gitignored) so a failed restore never
# forces a re-dump. Doctrine-only query params (serverVersion, charset) are
# stripped automatically, so URLs can be pasted straight from .env files.
#
# Everything is copied, including doctrine_migration_versions, so the app's
# boot-time `doctrine:migrations:migrate` is a no-op against the new database.
# =============================================================================
set -euo pipefail

# --- locate client tools ------------------------------------------------------
if [ -z "${PG_BIN:-}" ]; then
	if command -v pg_dump >/dev/null 2>&1; then
		PG_BIN="$(dirname "$(command -v pg_dump)")"
	else
		# EDB installer layout on macOS; pick the newest version present.
		PG_BIN="$(ls -d /Library/PostgreSQL/*/bin 2>/dev/null | sort -V | tail -1 || true)"
	fi
fi
if [ -z "$PG_BIN" ] || [ ! -x "$PG_BIN/pg_dump" ]; then
	echo "ERROR: pg_dump not found. Install PostgreSQL client tools or set PG_BIN." >&2
	exit 1
fi
echo "Using PostgreSQL client tools in $PG_BIN ($("$PG_BIN/pg_dump" --version))"

# --- resolve and sanitize URLs ------------------------------------------------
SRC="${1:-${SOURCE_DATABASE_URL:-}}"
TGT="${2:-${TARGET_DATABASE_URL:-}}"
if [ -z "$SRC" ] || { [ -z "$TGT" ] && [ "${DUMP_ONLY:-0}" != "1" ]; }; then
	echo "ERROR: set SOURCE_DATABASE_URL and TARGET_DATABASE_URL (or pass them as args)." >&2
	exit 1
fi

# Strip Doctrine-only params libpq would reject, keep everything else.
sanitize() {
	printf '%s' "$1" \
		| sed -E 's/([?&])serverVersion=[^&]*/\1/; s/([?&])charset=[^&]*/\1/' \
		| sed -E 's/&&+/\&/g; s/\?&/?/; s/[?&]+$//'
}
# Postgres-as-a-service providers all require TLS; add sslmode if missing.
ensure_ssl() {
	case "$1" in
		*sslmode=*) printf '%s' "$1" ;;
		*\?*)       printf '%s&sslmode=require' "$1" ;;
		*)          printf '%s?sslmode=require' "$1" ;;
	esac
}
SRC="$(ensure_ssl "$(sanitize "$SRC")")"
[ -n "$TGT" ] && TGT="$(ensure_ssl "$(sanitize "$TGT")")"

redact() { printf '%s\n' "$1" | sed -E 's#://([^:/@]+):[^@]*@#://\1:***@#'; }

# --- preflight ----------------------------------------------------------------
echo "Source: $(redact "$SRC")"
if ! "$PG_BIN/psql" "$SRC" -tAc "SELECT 'source ok: ' || current_database() || ' @ ' || version();"; then
	echo "ERROR: cannot connect to the SOURCE database." >&2
	echo "       For Render: check in the dashboard that the instance is not suspended/expired" >&2
	echo "       and that your IP is in the database's Access Control list." >&2
	exit 1
fi
if [ "${DUMP_ONLY:-0}" != "1" ]; then
	echo "Target: $(redact "$TGT")"
	"$PG_BIN/psql" "$TGT" -tAc "SELECT 'target ok: ' || current_database() || ' @ ' || version();" || {
		echo "ERROR: cannot connect to the TARGET database." >&2; exit 1; }
fi

# --- dump ---------------------------------------------------------------------
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
EXPORT_DIR="$ROOT/var/export"
mkdir -p "$EXPORT_DIR"
DUMP="$EXPORT_DIR/db-$(date +%Y%m%d-%H%M%S).dump"

echo "Dumping source -> $DUMP"
"$PG_BIN/pg_dump" --format=custom --no-owner --no-privileges --file="$DUMP" "$SRC"
echo "Dump complete: $(du -h "$DUMP" | cut -f1)"

if [ "${DUMP_ONLY:-0}" = "1" ]; then
	echo "DUMP_ONLY=1 — stopping after dump."
	exit 0
fi

# --- restore ------------------------------------------------------------------
RESTORE_FLAGS=(--no-owner --no-privileges --exit-on-error)
[ "${CLEAN:-0}" = "1" ] && RESTORE_FLAGS+=(--clean --if-exists)

echo "Restoring into target..."
"$PG_BIN/pg_restore" "${RESTORE_FLAGS[@]}" --dbname="$TGT" "$DUMP"

# --- verify: compare exact row counts table by table ---------------------------
echo "Verifying row counts..."
tables="$("$PG_BIN/psql" "$SRC" -tAc \
	"SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename;")"
fail=0
for t in $tables; do
	sc="$("$PG_BIN/psql" "$SRC" -tAc "SELECT count(*) FROM \"$t\";")"
	tc="$("$PG_BIN/psql" "$TGT" -tAc "SELECT count(*) FROM \"$t\";" 2>/dev/null || echo MISSING)"
	if [ "$sc" = "$tc" ]; then
		printf '  %-32s %8s rows  OK\n' "$t" "$sc"
	else
		printf '  %-32s source=%s target=%s  MISMATCH\n' "$t" "$sc" "$tc"
		fail=1
	fi
done

if [ "$fail" = "0" ]; then
	echo "Migration complete — all tables match."
else
	echo "Migration finished WITH MISMATCHES — inspect before switching the app over." >&2
	exit 1
fi
