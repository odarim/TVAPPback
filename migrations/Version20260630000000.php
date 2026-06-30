<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds:
 *  - active_session table (simultaneous stream tracking with token + heartbeat)
 *  - package.max_connections column (default 1)
 *  - user.max_devices_override column (nullable, user-level override)
 *  - user.max_connections_override column (nullable, user-level override)
 */
final class Version20260630000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add simultaneous connection limit: active_session table + max_connections on package + user-level overrides';
    }

    public function up(Schema $schema): void
    {
        // Add max_connections to package (default 1)
        $this->addSql('ALTER TABLE package ADD max_connections INT DEFAULT 1 NOT NULL');

        // Add user-level override fields (nullable — null means "use package default")
        $this->addSql('ALTER TABLE "user" ADD max_devices_override INT DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD max_connections_override INT DEFAULT NULL');

        // Create active_session table
        $this->addSql('CREATE TABLE active_session (
            id UUID NOT NULL,
            user_id UUID NOT NULL,
            device_id UUID NOT NULL,
            token VARCHAR(64) NOT NULL,
            started_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            last_heartbeat_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_ACTIVE_SESSION_TOKEN ON active_session (token)');
        $this->addSql('CREATE INDEX IDX_ACTIVE_SESSION_USER ON active_session (user_id)');
        $this->addSql('CREATE INDEX IDX_ACTIVE_SESSION_DEVICE ON active_session (device_id)');
        $this->addSql('ALTER TABLE active_session ADD CONSTRAINT FK_ACTIVE_SESSION_USER FOREIGN KEY (user_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE active_session ADD CONSTRAINT FK_ACTIVE_SESSION_DEVICE FOREIGN KEY (device_id) REFERENCES device (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE active_session DROP CONSTRAINT FK_ACTIVE_SESSION_USER');
        $this->addSql('ALTER TABLE active_session DROP CONSTRAINT FK_ACTIVE_SESSION_DEVICE');
        $this->addSql('DROP TABLE active_session');

        $this->addSql('ALTER TABLE package DROP max_connections');
        $this->addSql('ALTER TABLE "user" DROP max_devices_override');
        $this->addSql('ALTER TABLE "user" DROP max_connections_override');
    }
}
