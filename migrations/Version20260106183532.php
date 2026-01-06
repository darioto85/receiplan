<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260106183532 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        
        $this->addSql('ALTER TABLE ingredient ADD img_generated BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE ingredient ADD img_generated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE recipe ADD img_generated BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE recipe ADD img_generated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE ingredient DROP img_generated');
        $this->addSql('ALTER TABLE ingredient DROP img_generated_at');
        $this->addSql('CREATE UNIQUE INDEX uniq_ingredient_global_name_key ON ingredient (name_key) WHERE (user_id IS NULL)');
        $this->addSql('ALTER TABLE recipe DROP img_generated');
        $this->addSql('ALTER TABLE recipe DROP img_generated_at');
    }
}
