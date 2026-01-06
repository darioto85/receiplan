<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260106183436 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE ingredient ADD img_generated TINYINT DEFAULT 0 NOT NULL, ADD img_generated_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE recipe ADD img_generated TINYINT DEFAULT 0 NOT NULL, ADD img_generated_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE ingredient DROP img_generated, DROP img_generated_at');
        $this->addSql('ALTER TABLE recipe DROP img_generated, DROP img_generated_at');
    }
}
