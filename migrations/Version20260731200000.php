<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add package.has_vod_access so plans can opt in/out of the Movies & Series
 * (VOD) catalogue. Defaults to false (no VOD) for existing packages.
 */
final class Version20260731200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add has_vod_access column to the package table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE package ADD COLUMN has_vod_access BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE package DROP COLUMN has_vod_access');
    }
}
