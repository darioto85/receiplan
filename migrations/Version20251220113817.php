<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251220113817 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE meal_plan (id INT AUTO_INCREMENT NOT NULL, date DATE NOT NULL, validated TINYINT NOT NULL, user_id INT NOT NULL, recipe_id INT NOT NULL, INDEX IDX_C7848889A76ED395 (user_id), INDEX IDX_C784888959D8A214 (recipe_id), INDEX idx_meal_plan_user_date (user_id, date), INDEX idx_meal_plan_user_validated (user_id, validated), UNIQUE INDEX uniq_meal_plan_user_recipe_date (user_id, recipe_id, date), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE meal_plan ADD CONSTRAINT FK_C7848889A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE meal_plan ADD CONSTRAINT FK_C784888959D8A214 FOREIGN KEY (recipe_id) REFERENCES recipe (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_ingredient DROP FOREIGN KEY `FK_CCC8BE9C933FE08C`');
        $this->addSql('ALTER TABLE user_ingredient CHANGE quantity quantity NUMERIC(10, 2) DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE user_ingredient ADD CONSTRAINT FK_CCC8BE9C933FE08C FOREIGN KEY (ingredient_id) REFERENCES ingredient (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE meal_plan DROP FOREIGN KEY FK_C7848889A76ED395');
        $this->addSql('ALTER TABLE meal_plan DROP FOREIGN KEY FK_C784888959D8A214');
        $this->addSql('DROP TABLE meal_plan');
        $this->addSql('ALTER TABLE user_ingredient DROP FOREIGN KEY FK_CCC8BE9C933FE08C');
        $this->addSql('ALTER TABLE user_ingredient CHANGE quantity quantity NUMERIC(10, 2) NOT NULL');
        $this->addSql('ALTER TABLE user_ingredient ADD CONSTRAINT `FK_CCC8BE9C933FE08C` FOREIGN KEY (ingredient_id) REFERENCES ingredient (id) ON UPDATE NO ACTION ON DELETE NO ACTION');
    }
}
