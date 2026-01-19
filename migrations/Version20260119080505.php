<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260119080505 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        
        $this->addSql('ALTER TABLE app_user ADD is_verified BOOLEAN DEFAULT false NOT NULL');

        $this->addSql('ALTER TABLE app_user ADD created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP');
        $this->addSql('UPDATE app_user SET created_at = CURRENT_TIMESTAMP WHERE created_at IS NULL');
        $this->addSql('ALTER TABLE app_user ALTER COLUMN created_at SET NOT NULL');
        $this->addSql('ALTER TABLE app_user ALTER COLUMN created_at DROP DEFAULT');

        $this->addSql('ALTER TABLE app_user ADD last_login_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE app_user ADD google_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE app_user ADD apple_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE app_user ADD password_reset_token VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE app_user ADD password_reset_requested_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE app_user ADD password_reset_expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE app_user ALTER password DROP NOT NULL');

        $this->addSql('CREATE UNIQUE INDEX uniq_user_google_id ON app_user (google_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_user_apple_id ON app_user (apple_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_user_password_reset_token ON app_user (password_reset_token)');

        
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX uniq_user_google_id');
        $this->addSql('DROP INDEX uniq_user_apple_id');
        $this->addSql('DROP INDEX uniq_user_password_reset_token');
        $this->addSql('ALTER TABLE app_user DROP is_verified');
        $this->addSql('ALTER TABLE app_user DROP created_at');
        $this->addSql('ALTER TABLE app_user DROP last_login_at');
        $this->addSql('ALTER TABLE app_user DROP google_id');
        $this->addSql('ALTER TABLE app_user DROP apple_id');
        $this->addSql('ALTER TABLE app_user DROP password_reset_token');
        $this->addSql('ALTER TABLE app_user DROP password_reset_requested_at');
        $this->addSql('ALTER TABLE app_user DROP password_reset_expires_at');
        $this->addSql('ALTER TABLE app_user ALTER password SET NOT NULL');
    }
}
