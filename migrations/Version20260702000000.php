<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Widen channel.country from VARCHAR(2) to VARCHAR(100) so that full country
 * names coming from the livewatch.top public API can be stored as-is.
 */
final class Version20260702000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Widen channel.country to VARCHAR(100) for full country names (livewatch import)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE channel ALTER COLUMN country TYPE VARCHAR(100)');
    }

    public function down(Schema $schema): void
    {
        // Truncate data that would violate the constraint before downgrading
        $this->addSql("UPDATE channel SET country = LEFT(country, 2) WHERE LENGTH(country) > 2");
        $this->addSql('ALTER TABLE channel ALTER COLUMN country TYPE VARCHAR(2)');
    }
}
