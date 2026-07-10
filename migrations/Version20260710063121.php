<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260710063121 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE torrent_session (id UUID NOT NULL, session_id VARCHAR(36) NOT NULL, status VARCHAR(30) NOT NULL, stream_token VARCHAR(64) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_activity TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_11AF26F3613FECDF ON torrent_session (session_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_11AF26F3105F4450 ON torrent_session (stream_token)');
        $this->addSql('CREATE INDEX IDX_11AF26F3A76ED395 ON torrent_session (user_id)');
        $this->addSql('ALTER TABLE torrent_session ADD CONSTRAINT FK_11AF26F3A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE torrent_session DROP CONSTRAINT FK_11AF26F3A76ED395');
        $this->addSql('DROP TABLE torrent_session');
    }
}
