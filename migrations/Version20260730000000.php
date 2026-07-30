<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260730000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add createdAt and deviceType fields to Device entity for Netflix-style device management';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE device ADD device_type VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE device ADD created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW()');
        $this->addSql('ALTER TABLE device ALTER COLUMN created_at DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE device DROP device_type');
        $this->addSql('ALTER TABLE device DROP created_at');
    }
}
