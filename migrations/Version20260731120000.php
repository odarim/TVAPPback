<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Widen channel.logo so long logo URLs (e.g. wsrv.nl-wrapped Wikimedia
 * thumbnails) fit instead of triggering a "value too long" DB error.
 */
final class Version20260731120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Increase channel.logo column length to 2048 characters';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE channel ALTER COLUMN logo TYPE VARCHAR(2048)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE channel ALTER COLUMN logo TYPE VARCHAR(255)');
    }
}
