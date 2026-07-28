<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260728123901 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add adult-content lock: user lock credentials + device unlock state';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD adult_lock_password_hash VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD adult_lock_salt VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD adult_lock_updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE device ADD adult_content_unlocked BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE device ADD adult_content_unlocked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP adult_lock_password_hash');
        $this->addSql('ALTER TABLE "user" DROP adult_lock_salt');
        $this->addSql('ALTER TABLE "user" DROP adult_lock_updated_at');
        $this->addSql('ALTER TABLE device DROP adult_content_unlocked');
        $this->addSql('ALTER TABLE device DROP adult_content_unlocked_at');
    }
}
