<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260113204144 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE meal_cooked_prompt (id INT AUTO_INCREMENT NOT NULL, date DATE NOT NULL, status VARCHAR(16) NOT NULL, answer VARCHAR(8) DEFAULT NULL, sent_at DATETIME NOT NULL, answered_at DATETIME DEFAULT NULL, context VARCHAR(64) NOT NULL, user_id INT NOT NULL, meal_plan_id INT NOT NULL, INDEX IDX_527C1AF1A76ED395 (user_id), INDEX IDX_527C1AF1912AB082 (meal_plan_id), INDEX idx_meal_cooked_prompt_status_date (status, date), INDEX idx_meal_cooked_prompt_user_status (user_id, status), UNIQUE INDEX uniq_meal_cooked_prompt_user_date (user_id, date), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE meal_cooked_prompt ADD CONSTRAINT FK_527C1AF1A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE meal_cooked_prompt ADD CONSTRAINT FK_527C1AF1912AB082 FOREIGN KEY (meal_plan_id) REFERENCES meal_plan (id) ON DELETE CASCADE');
        
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE meal_cooked_prompt DROP FOREIGN KEY FK_527C1AF1A76ED395');
        $this->addSql('ALTER TABLE meal_cooked_prompt DROP FOREIGN KEY FK_527C1AF1912AB082');
        $this->addSql('DROP TABLE meal_cooked_prompt');
        
    }
}
