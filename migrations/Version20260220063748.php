<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260220063748 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE category (id UUID NOT NULL, name VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL, description VARCHAR(255) DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE channel (id UUID NOT NULL, nanoid VARCHAR(255) DEFAULT NULL, name VARCHAR(255) NOT NULL, slug VARCHAR(255) DEFAULT NULL, language VARCHAR(10) DEFAULT NULL, country VARCHAR(2) DEFAULT NULL, is_geo_blocked BOOLEAN NOT NULL, logo VARCHAR(255) DEFAULT NULL, is_active BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, category_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_A2F98E4712469DE2 ON channel (category_id)');
        $this->addSql('CREATE TABLE channel_stream (id UUID NOT NULL, type VARCHAR(255) NOT NULL, url VARCHAR(1024) NOT NULL, channel_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_E8A846272F5A1AA ON channel_stream (channel_id)');
        $this->addSql('CREATE TABLE device (id UUID NOT NULL, device_id VARCHAR(255) NOT NULL, device_name VARCHAR(255) DEFAULT NULL, last_active_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_92FB68EA76ED395 ON device (user_id)');
        $this->addSql('CREATE TABLE package (id UUID NOT NULL, name VARCHAR(255) NOT NULL, description VARCHAR(1024) DEFAULT NULL, price DOUBLE PRECISION NOT NULL, max_devices INT NOT NULL, is_active BOOLEAN NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE package_channel (package_id UUID NOT NULL, channel_id UUID NOT NULL, PRIMARY KEY (package_id, channel_id))');
        $this->addSql('CREATE INDEX IDX_2DA2EBCBF44CABFF ON package_channel (package_id)');
        $this->addSql('CREATE INDEX IDX_2DA2EBCB72F5A1AA ON package_channel (channel_id)');
        $this->addSql('CREATE TABLE subscription (id UUID NOT NULL, start_date TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, end_date TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, is_active BOOLEAN NOT NULL, user_id UUID NOT NULL, package_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_A3C664D3A76ED395 ON subscription (user_id)');
        $this->addSql('CREATE INDEX IDX_A3C664D3F44CABFF ON subscription (package_id)');
        $this->addSql('CREATE TABLE "user" (id UUID NOT NULL, email VARCHAR(180) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, full_name VARCHAR(255) DEFAULT NULL, country VARCHAR(2) DEFAULT NULL, is_active BOOLEAN DEFAULT true NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D649E7927C74 ON "user" (email)');
        $this->addSql('ALTER TABLE channel ADD CONSTRAINT FK_A2F98E4712469DE2 FOREIGN KEY (category_id) REFERENCES category (id)');
        $this->addSql('ALTER TABLE channel_stream ADD CONSTRAINT FK_E8A846272F5A1AA FOREIGN KEY (channel_id) REFERENCES channel (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE device ADD CONSTRAINT FK_92FB68EA76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE package_channel ADD CONSTRAINT FK_2DA2EBCBF44CABFF FOREIGN KEY (package_id) REFERENCES package (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE package_channel ADD CONSTRAINT FK_2DA2EBCB72F5A1AA FOREIGN KEY (channel_id) REFERENCES channel (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE subscription ADD CONSTRAINT FK_A3C664D3A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE subscription ADD CONSTRAINT FK_A3C664D3F44CABFF FOREIGN KEY (package_id) REFERENCES package (id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE channel DROP CONSTRAINT FK_A2F98E4712469DE2');
        $this->addSql('ALTER TABLE channel_stream DROP CONSTRAINT FK_E8A846272F5A1AA');
        $this->addSql('ALTER TABLE device DROP CONSTRAINT FK_92FB68EA76ED395');
        $this->addSql('ALTER TABLE package_channel DROP CONSTRAINT FK_2DA2EBCBF44CABFF');
        $this->addSql('ALTER TABLE package_channel DROP CONSTRAINT FK_2DA2EBCB72F5A1AA');
        $this->addSql('ALTER TABLE subscription DROP CONSTRAINT FK_A3C664D3A76ED395');
        $this->addSql('ALTER TABLE subscription DROP CONSTRAINT FK_A3C664D3F44CABFF');
        $this->addSql('DROP TABLE category');
        $this->addSql('DROP TABLE channel');
        $this->addSql('DROP TABLE channel_stream');
        $this->addSql('DROP TABLE device');
        $this->addSql('DROP TABLE package');
        $this->addSql('DROP TABLE package_channel');
        $this->addSql('DROP TABLE subscription');
        $this->addSql('DROP TABLE "user"');
    }
}
