<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260220101957 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE user_favorites (user_id UUID NOT NULL, channel_id UUID NOT NULL, PRIMARY KEY (user_id, channel_id))');
        $this->addSql('CREATE INDEX IDX_E489ED11A76ED395 ON user_favorites (user_id)');
        $this->addSql('CREATE INDEX IDX_E489ED1172F5A1AA ON user_favorites (channel_id)');
        $this->addSql('ALTER TABLE user_favorites ADD CONSTRAINT FK_E489ED11A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_favorites ADD CONSTRAINT FK_E489ED1172F5A1AA FOREIGN KEY (channel_id) REFERENCES channel (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE channel ADD view_count INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE user_favorites DROP CONSTRAINT FK_E489ED11A76ED395');
        $this->addSql('ALTER TABLE user_favorites DROP CONSTRAINT FK_E489ED1172F5A1AA');
        $this->addSql('DROP TABLE user_favorites');
        $this->addSql('ALTER TABLE channel DROP view_count');
    }
}
