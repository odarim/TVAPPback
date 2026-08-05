# Migrating the database: Render Postgres → Aiven for PostgreSQL

Everything is prepared in the repo; the only inputs needed are the two
connection URLs. The copy itself is one command (step 3).

> **Status (2026-08-05):** the Render database (`tvapp-db`) is **gone** — its
> internal hostname `dpg-d92ck8btqb8s73f91vpg-a` no longer resolves inside
> Render's network (deploys failed with "could not translate host name"), and
> external connections were already being dropped before authentication.
> Unless the Render dashboard still shows the instance in a recoverable
> (suspended) state, skip step 2/3 and use one of the fallbacks in step 2 —
> the Supabase source, or a fresh empty schema on Aiven.

## 1. Create the Aiven service and get its URL

1. In the [Aiven console](https://console.aiven.io), inside your project:
   **Create service → PostgreSQL → Free plan**, pick a region close to
   Frankfurt (the Render app region).
2. Wait until the service state is *Running*.
3. Copy **Overview → Connection information → Service URI**. It looks like:

   ```
   postgres://avnadmin:<password>@<service>-<project>.<region>.aivencloud.com:<port>/defaultdb?sslmode=require
   ```

   Note: **Kafka is a different Aiven product** (event streaming) — the app
   needs the *PostgreSQL* service. The free plan is roughly 1 CPU / 1 GB RAM /
   5 GB storage, single node.

## 2. Make the source (Render) reachable

`bin/migrate-db.sh` connects to the Render DB from your machine using the
**External Database URL**. Right now that connection is refused. In the Render
dashboard → PostgreSQL `tvapp-db`, check:

- **Is the instance suspended or expired?** Free Render Postgres instances
  expire ~30 days after creation and are deleted ~14 days later. If it is
  suspended, resume/upgrade it *before it is deleted* — free instances have no
  backups to restore from.
- **Access Control / IP allowlist:** your current public IP must be listed
  (or `0.0.0.0/0` temporarily while migrating).
- Confirm the **External Database URL** still matches the one in `.env.local`
  (credentials rotate if the instance was recreated).

**If the Render DB is already gone:** if the app ever ran with
`DB_PROVIDER=supabase`, the live data may be in Supabase — the same script
migrates Supabase → Aiven, just use the Supabase Session-pooler URL as the
source. If there is no surviving data anywhere, start empty: point the app at
Aiven (step 4) and the boot-time `doctrine:migrations:migrate` creates the
full schema on its own; set `RUN_SEED=true` for the first boot to seed the
admin user and base data, then set it back to `false`.

## 3. Run the migration

```sh
SOURCE_DATABASE_URL="<Render external URL, see .env.local>" \
TARGET_DATABASE_URL="<Aiven Service URI>" \
bin/migrate-db.sh
```

The script:
- finds local `pg_dump`/`pg_restore` (also in `/Library/PostgreSQL/*/bin`),
- strips Doctrine-only URL params (`serverVersion`, `charset`) so URLs can be
  pasted straight from `.env` files, and forces `sslmode=require`,
- dumps to `var/export/db-<timestamp>.dump` (gitignored, reusable),
- restores into Aiven (`--no-owner --no-privileges`, so the non-superuser
  `avnadmin` role works),
- compares exact row counts table-by-table and fails loudly on any mismatch.

Re-running after a partial restore: add `CLEAN=1` to drop and recreate the
objects. To only take a backup without restoring: `DUMP_ONLY=1`.

`doctrine_migration_versions` is copied too, so the container's boot-time
`doctrine:migrations:migrate` is a no-op against the new database.

## 4. Point the app at Aiven

1. Render dashboard → `tvapp-backend` → **Environment**:
   - `AIVEN_DATABASE_URL` = the Aiven Service URI (already declared with
     `sync: false` in `render.yaml`, so the dashboard is where the value lives)
   - `DB_PROVIDER` = `aiven`
2. Deploy (push to `main` — CI triggers the deploy hook — or "Manual Deploy").
3. Verify `https://tvappback.onrender.com/health` and log in from the app.

The entrypoint (`docker/docker-entrypoint.sh`) resolves `DB_PROVIDER=aiven` →
`AIVEN_DATABASE_URL` and normalizes it (`sslmode=require`, `serverVersion=17`
default, override with `DATABASE_SERVER_VERSION`).

## 5. Afterwards

- Update `DATABASE_URL` in your local `.env.local` — it still points at the
  (dying) Render DB.
- Keep the last `var/export/db-*.dump` somewhere safe; the Aiven free plan is
  a single node — that dump is your backup until you upgrade.
- The `tvapp-db` block and `RENDER_DATABASE_URL` have been removed from
  `render.yaml`, and the entrypoint's default provider is now `aiven` — a
  container without any `DB_PROVIDER` set no longer falls back to the dead
  Render database.
