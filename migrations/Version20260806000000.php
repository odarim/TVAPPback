<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add channel_viewer table to track "watching now" live viewers per channel.
 * Each row is a device actively watching; heartbeats refresh last_heartbeat_at
 * and expired rows are pruned, so COUNT(rows) == live viewers.
 */
final class Version20260806000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add channel_viewer table for live watching-now viewers';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE channel_viewer (id UUID NOT NULL, channel_id UUID NOT NULL, user_id UUID DEFAULT NULL, token VARCHAR(64) NOT NULL, device_id VARCHAR(128) DEFAULT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_heartbeat_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_CHANNEL_VIEWER_TOKEN ON channel_viewer (token)');
        $this->addSql('CREATE INDEX IDX_CHANNEL_VIEWER_CHANNEL_HEARTBEAT ON channel_viewer (channel_id, last_heartbeat_at)');
        $this->addSql('CREATE INDEX IDX_CHANNEL_VIEWER_USER ON channel_viewer (user_id)');
        $this->addSql('ALTER TABLE channel_viewer ADD CONSTRAINT FK_CHANNEL_VIEWER_CHANNEL FOREIGN KEY (channel_id) REFERENCES channel (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE channel_viewer ADD CONSTRAINT FK_CHANNEL_VIEWER_USER FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE channel_viewer');
    }
}