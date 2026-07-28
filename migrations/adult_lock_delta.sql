-- =============================================================================
-- Delta: adult-content lock (user lock credentials + device unlock state)
-- =============================================================================
-- Standalone, idempotent version of migration Version20260728123901.
-- Run this against a database where the Doctrine migration cannot be run
-- directly (e.g. paste into the Render dashboard SQL console, or the Supabase
-- SQL editor when DB_PROVIDER=supabase).
--
-- Already applied to the Render database (tvapp-db) via
--   php bin/console doctrine:migrations:migrate
-- so you only need this for OTHER databases / fresh provisions.
--
-- Safe to run more than once: every column add is guarded.
-- =============================================================================

BEGIN;

ALTER TABLE "user" ADD COLUMN IF NOT EXISTS adult_lock_password_hash VARCHAR(255) DEFAULT NULL;
ALTER TABLE "user" ADD COLUMN IF NOT EXISTS adult_lock_salt          VARCHAR(64)  DEFAULT NULL;
ALTER TABLE "user" ADD COLUMN IF NOT EXISTS adult_lock_updated_at    TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL;

ALTER TABLE device ADD COLUMN IF NOT EXISTS adult_content_unlocked    BOOLEAN      DEFAULT false NOT NULL;
ALTER TABLE device ADD COLUMN IF NOT EXISTS adult_content_unlocked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL;

-- Mark the Doctrine migration as applied so the app's boot-time
-- `doctrine:migrations:migrate` treats it as a no-op.
INSERT INTO doctrine_migration_versions (version, executed_at, execution_time)
VALUES ('DoctrineMigrations\Version20260728123901', NOW(), 0)
ON CONFLICT (version) DO NOTHING;

COMMIT;
