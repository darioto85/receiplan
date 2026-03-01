<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260301103829 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE app_user (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) DEFAULT NULL, is_verified TINYINT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, last_login_at DATETIME DEFAULT NULL, google_id VARCHAR(255) DEFAULT NULL, apple_id VARCHAR(255) DEFAULT NULL, password_reset_token VARCHAR(255) DEFAULT NULL, password_reset_requested_at DATETIME DEFAULT NULL, password_reset_expires_at DATETIME DEFAULT NULL, UNIQUE INDEX uniq_user_email (email), UNIQUE INDEX uniq_user_google_id (google_id), UNIQUE INDEX uniq_user_apple_id (apple_id), UNIQUE INDEX uniq_user_password_reset_token (password_reset_token), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE assistant_conversation (id INT AUTO_INCREMENT NOT NULL, day DATE NOT NULL, created_at DATETIME NOT NULL, user_id INT NOT NULL, INDEX IDX_5BF3529BA76ED395 (user_id), UNIQUE INDEX uniq_user_day (user_id, day), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE assistant_message (id INT AUTO_INCREMENT NOT NULL, role VARCHAR(20) NOT NULL, content LONGTEXT NOT NULL, payload JSON DEFAULT NULL, created_at DATETIME NOT NULL, conversation_id INT NOT NULL, INDEX IDX_8A36E1EF9AC0396 (conversation_id), INDEX idx_conversation_created (conversation_id, created_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE daily_meal_suggestion (id INT AUTO_INCREMENT NOT NULL, date DATE NOT NULL, status VARCHAR(32) NOT NULL, context VARCHAR(32) NOT NULL, generated_at DATETIME NOT NULL, meta JSON DEFAULT NULL, user_id INT NOT NULL, meal_plan_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_EAC4777F912AB082 (meal_plan_id), INDEX idx_daily_suggestion_date (date), INDEX idx_daily_suggestion_user (user_id), UNIQUE INDEX uniq_daily_suggestion_user_date (user_id, date), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE ingredient (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, name_key VARCHAR(255) NOT NULL, category VARCHAR(255) DEFAULT NULL, unit VARCHAR(255) NOT NULL, img_generated TINYINT DEFAULT 0 NOT NULL, img_generated_at DATETIME DEFAULT NULL, user_id INT DEFAULT NULL, INDEX IDX_6BAF7870A76ED395 (user_id), UNIQUE INDEX uniq_ingredient_user_name_key (user_id, name_key), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE meal_cooked_prompt (id INT AUTO_INCREMENT NOT NULL, date DATE NOT NULL, status VARCHAR(16) NOT NULL, answer VARCHAR(8) DEFAULT NULL, sent_at DATETIME DEFAULT NULL, answered_at DATETIME DEFAULT NULL, context VARCHAR(64) NOT NULL, user_id INT NOT NULL, meal_plan_id INT NOT NULL, INDEX IDX_527C1AF1A76ED395 (user_id), INDEX IDX_527C1AF1912AB082 (meal_plan_id), INDEX idx_meal_cooked_prompt_status_date (status, date), INDEX idx_meal_cooked_prompt_user_status (user_id, status), UNIQUE INDEX uniq_meal_cooked_prompt_user_date (user_id, date), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE meal_plan (id INT AUTO_INCREMENT NOT NULL, date DATE NOT NULL, validated TINYINT NOT NULL, user_id INT NOT NULL, recipe_id INT NOT NULL, INDEX IDX_C7848889A76ED395 (user_id), INDEX IDX_C784888959D8A214 (recipe_id), INDEX idx_meal_plan_user_date (user_id, date), INDEX idx_meal_plan_user_validated (user_id, validated), UNIQUE INDEX uniq_meal_plan_user_recipe_date (user_id, recipe_id, date), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE preinscription (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX uniq_preinscription_email (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE push_subscription (id INT AUTO_INCREMENT NOT NULL, endpoint_hash VARCHAR(64) NOT NULL, endpoint LONGTEXT NOT NULL, p256dh VARCHAR(255) NOT NULL, auth VARCHAR(255) NOT NULL, content_encoding VARCHAR(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, last_used_at DATETIME DEFAULT NULL, user_agent VARCHAR(255) DEFAULT NULL, user_id INT NOT NULL, INDEX idx_push_subscription_user (user_id), UNIQUE INDEX uniq_push_subscription_endpoint_hash (endpoint_hash), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE recipe (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, name_key VARCHAR(255) DEFAULT NULL, img_generated TINYINT DEFAULT 0 NOT NULL, img_generated_at DATETIME DEFAULT NULL, favorite TINYINT DEFAULT 0 NOT NULL, draft TINYINT DEFAULT 0 NOT NULL, user_id INT NOT NULL, INDEX IDX_DA88B137A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE recipe_ingredient (id INT AUTO_INCREMENT NOT NULL, quantity NUMERIC(10, 2) NOT NULL, unit VARCHAR(255) NOT NULL, recipe_id INT NOT NULL, ingredient_id INT NOT NULL, INDEX IDX_22D1FE1359D8A214 (recipe_id), INDEX IDX_22D1FE13933FE08C (ingredient_id), UNIQUE INDEX uniq_recipe_ingredient (recipe_id, ingredient_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE recipe_step (id INT AUTO_INCREMENT NOT NULL, content LONGTEXT NOT NULL, position INT DEFAULT 1 NOT NULL, recipe_id INT NOT NULL, INDEX IDX_3CA2A4E359D8A214 (recipe_id), INDEX idx_recipe_step_recipe_position (recipe_id, position), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE shopping (id INT AUTO_INCREMENT NOT NULL, quantity NUMERIC(10, 2) NOT NULL, source VARCHAR(16) NOT NULL, checked TINYINT NOT NULL, checked_at DATETIME DEFAULT NULL, unit VARCHAR(255) NOT NULL, user_id INT NOT NULL, ingredient_id INT NOT NULL, INDEX IDX_FB45F439A76ED395 (user_id), INDEX IDX_FB45F439933FE08C (ingredient_id), UNIQUE INDEX uniq_user_ingredient (user_id, ingredient_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE user_ingredient (id INT AUTO_INCREMENT NOT NULL, quantity NUMERIC(10, 2) NOT NULL, unit VARCHAR(255) NOT NULL, user_id INT NOT NULL, ingredient_id INT NOT NULL, INDEX IDX_CCC8BE9CA76ED395 (user_id), INDEX IDX_CCC8BE9C933FE08C (ingredient_id), UNIQUE INDEX uniq_user_ingredient (user_id, ingredient_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0 (queue_name), INDEX IDX_75EA56E0E3BD61CE (available_at), INDEX IDX_75EA56E016BA31DB (delivered_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE assistant_conversation ADD CONSTRAINT FK_5BF3529BA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assistant_message ADD CONSTRAINT FK_8A36E1EF9AC0396 FOREIGN KEY (conversation_id) REFERENCES assistant_conversation (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE daily_meal_suggestion ADD CONSTRAINT FK_EAC4777FA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE daily_meal_suggestion ADD CONSTRAINT FK_EAC4777F912AB082 FOREIGN KEY (meal_plan_id) REFERENCES meal_plan (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE ingredient ADD CONSTRAINT FK_6BAF7870A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE meal_cooked_prompt ADD CONSTRAINT FK_527C1AF1A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE meal_cooked_prompt ADD CONSTRAINT FK_527C1AF1912AB082 FOREIGN KEY (meal_plan_id) REFERENCES meal_plan (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE meal_plan ADD CONSTRAINT FK_C7848889A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE meal_plan ADD CONSTRAINT FK_C784888959D8A214 FOREIGN KEY (recipe_id) REFERENCES recipe (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE push_subscription ADD CONSTRAINT FK_562830F3A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE recipe ADD CONSTRAINT FK_DA88B137A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE recipe_ingredient ADD CONSTRAINT FK_22D1FE1359D8A214 FOREIGN KEY (recipe_id) REFERENCES recipe (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE recipe_ingredient ADD CONSTRAINT FK_22D1FE13933FE08C FOREIGN KEY (ingredient_id) REFERENCES ingredient (id)');
        $this->addSql('ALTER TABLE recipe_step ADD CONSTRAINT FK_3CA2A4E359D8A214 FOREIGN KEY (recipe_id) REFERENCES recipe (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE shopping ADD CONSTRAINT FK_FB45F439A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id)');
        $this->addSql('ALTER TABLE shopping ADD CONSTRAINT FK_FB45F439933FE08C FOREIGN KEY (ingredient_id) REFERENCES ingredient (id)');
        $this->addSql('ALTER TABLE user_ingredient ADD CONSTRAINT FK_CCC8BE9CA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_ingredient ADD CONSTRAINT FK_CCC8BE9C933FE08C FOREIGN KEY (ingredient_id) REFERENCES ingredient (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE assistant_conversation DROP FOREIGN KEY FK_5BF3529BA76ED395');
        $this->addSql('ALTER TABLE assistant_message DROP FOREIGN KEY FK_8A36E1EF9AC0396');
        $this->addSql('ALTER TABLE daily_meal_suggestion DROP FOREIGN KEY FK_EAC4777FA76ED395');
        $this->addSql('ALTER TABLE daily_meal_suggestion DROP FOREIGN KEY FK_EAC4777F912AB082');
        $this->addSql('ALTER TABLE ingredient DROP FOREIGN KEY FK_6BAF7870A76ED395');
        $this->addSql('ALTER TABLE meal_cooked_prompt DROP FOREIGN KEY FK_527C1AF1A76ED395');
        $this->addSql('ALTER TABLE meal_cooked_prompt DROP FOREIGN KEY FK_527C1AF1912AB082');
        $this->addSql('ALTER TABLE meal_plan DROP FOREIGN KEY FK_C7848889A76ED395');
        $this->addSql('ALTER TABLE meal_plan DROP FOREIGN KEY FK_C784888959D8A214');
        $this->addSql('ALTER TABLE push_subscription DROP FOREIGN KEY FK_562830F3A76ED395');
        $this->addSql('ALTER TABLE recipe DROP FOREIGN KEY FK_DA88B137A76ED395');
        $this->addSql('ALTER TABLE recipe_ingredient DROP FOREIGN KEY FK_22D1FE1359D8A214');
        $this->addSql('ALTER TABLE recipe_ingredient DROP FOREIGN KEY FK_22D1FE13933FE08C');
        $this->addSql('ALTER TABLE recipe_step DROP FOREIGN KEY FK_3CA2A4E359D8A214');
        $this->addSql('ALTER TABLE shopping DROP FOREIGN KEY FK_FB45F439A76ED395');
        $this->addSql('ALTER TABLE shopping DROP FOREIGN KEY FK_FB45F439933FE08C');
        $this->addSql('ALTER TABLE user_ingredient DROP FOREIGN KEY FK_CCC8BE9CA76ED395');
        $this->addSql('ALTER TABLE user_ingredient DROP FOREIGN KEY FK_CCC8BE9C933FE08C');
        $this->addSql('DROP TABLE app_user');
        $this->addSql('DROP TABLE assistant_conversation');
        $this->addSql('DROP TABLE assistant_message');
        $this->addSql('DROP TABLE daily_meal_suggestion');
        $this->addSql('DROP TABLE ingredient');
        $this->addSql('DROP TABLE meal_cooked_prompt');
        $this->addSql('DROP TABLE meal_plan');
        $this->addSql('DROP TABLE preinscription');
        $this->addSql('DROP TABLE push_subscription');
        $this->addSql('DROP TABLE recipe');
        $this->addSql('DROP TABLE recipe_ingredient');
        $this->addSql('DROP TABLE recipe_step');
        $this->addSql('DROP TABLE shopping');
        $this->addSql('DROP TABLE user_ingredient');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
