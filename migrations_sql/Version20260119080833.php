<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260119080833 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql("ALTER TABLE app_user ADD created_at DATETIME DEFAULT NULL");
        $this->addSql("UPDATE app_user SET created_at = NOW() WHERE created_at IS NULL");
        $this->addSql("ALTER TABLE app_user MODIFY created_at DATETIME NOT NULL");

        $this->addSql('ALTER TABLE app_user ADD is_verified TINYINT DEFAULT 0 NOT NULL,ADD last_login_at DATETIME DEFAULT NULL, ADD google_id VARCHAR(255) DEFAULT NULL, ADD apple_id VARCHAR(255) DEFAULT NULL, ADD password_reset_token VARCHAR(255) DEFAULT NULL, ADD password_reset_requested_at DATETIME DEFAULT NULL, ADD password_reset_expires_at DATETIME DEFAULT NULL, CHANGE password password VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_user_google_id ON app_user (google_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_user_apple_id ON app_user (apple_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_user_password_reset_token ON app_user (password_reset_token)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX uniq_user_google_id ON app_user');
        $this->addSql('DROP INDEX uniq_user_apple_id ON app_user');
        $this->addSql('DROP INDEX uniq_user_password_reset_token ON app_user');
        $this->addSql('ALTER TABLE app_user DROP is_verified, DROP created_at, DROP last_login_at, DROP google_id, DROP apple_id, DROP password_reset_token, DROP password_reset_requested_at, DROP password_reset_expires_at, CHANGE password password VARCHAR(255) NOT NULL');
    
    }
}
