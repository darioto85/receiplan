<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260108154404 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE daily_meal_suggestion (id INT AUTO_INCREMENT NOT NULL, date DATE NOT NULL, status VARCHAR(32) NOT NULL, context VARCHAR(32) NOT NULL, generated_at DATETIME NOT NULL, meta JSON DEFAULT NULL, user_id INT NOT NULL, meal_plan_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_EAC4777F912AB082 (meal_plan_id), INDEX idx_daily_suggestion_date (date), INDEX idx_daily_suggestion_user (user_id), UNIQUE INDEX uniq_daily_suggestion_user_date (user_id, date), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE daily_meal_suggestion ADD CONSTRAINT FK_EAC4777FA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE daily_meal_suggestion ADD CONSTRAINT FK_EAC4777F912AB082 FOREIGN KEY (meal_plan_id) REFERENCES meal_plan (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE daily_meal_suggestion DROP FOREIGN KEY FK_EAC4777FA76ED395');
        $this->addSql('ALTER TABLE daily_meal_suggestion DROP FOREIGN KEY FK_EAC4777F912AB082');
        $this->addSql('DROP TABLE daily_meal_suggestion');
    }
}
