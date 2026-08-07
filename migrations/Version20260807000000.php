<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add a human-readable `label` column to channel_stream so merged channels
 * can show distinct, descriptive sources (e.g. language/country) in the
 * frontend source switcher.
 */
final class Version20260807000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add label column to channel_stream for descriptive source labels';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE channel_stream ADD label VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE channel_stream DROP COLUMN label');
    }
}