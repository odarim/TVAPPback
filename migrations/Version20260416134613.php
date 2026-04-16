<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260416134613 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE pending_payment (id UUID NOT NULL, reference VARCHAR(64) NOT NULL, processed BOOLEAN DEFAULT false NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, package_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_A647739CAEA34913 ON pending_payment (reference)');
        $this->addSql('CREATE INDEX IDX_A647739CA76ED395 ON pending_payment (user_id)');
        $this->addSql('CREATE INDEX IDX_A647739CF44CABFF ON pending_payment (package_id)');
        $this->addSql('ALTER TABLE pending_payment ADD CONSTRAINT FK_A647739CA76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE pending_payment ADD CONSTRAINT FK_A647739CF44CABFF FOREIGN KEY (package_id) REFERENCES package (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE channel ALTER is_working SET NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE pending_payment DROP CONSTRAINT FK_A647739CA76ED395');
        $this->addSql('ALTER TABLE pending_payment DROP CONSTRAINT FK_A647739CF44CABFF');
        $this->addSql('DROP TABLE pending_payment');
        $this->addSql('ALTER TABLE channel ALTER is_working DROP NOT NULL');
    }
}
