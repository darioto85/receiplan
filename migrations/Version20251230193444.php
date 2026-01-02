<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251230193444 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE shopping (id INT AUTO_INCREMENT NOT NULL, quantity NUMERIC(10, 2) NOT NULL, source VARCHAR(16) NOT NULL, checked TINYINT NOT NULL, checked_at DATETIME DEFAULT NULL, user_id INT NOT NULL, ingredient_id INT NOT NULL, INDEX IDX_FB45F439A76ED395 (user_id), INDEX IDX_FB45F439933FE08C (ingredient_id), UNIQUE INDEX uniq_user_ingredient (user_id, ingredient_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE shopping ADD CONSTRAINT FK_FB45F439A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id)');
        $this->addSql('ALTER TABLE shopping ADD CONSTRAINT FK_FB45F439933FE08C FOREIGN KEY (ingredient_id) REFERENCES ingredient (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE shopping DROP FOREIGN KEY FK_FB45F439A76ED395');
        $this->addSql('ALTER TABLE shopping DROP FOREIGN KEY FK_FB45F439933FE08C');
        $this->addSql('DROP TABLE shopping');
    }
}
