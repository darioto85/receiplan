<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251219082522 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE ingredient ADD user_id INT NOT NULL AFTER id');
        $this->addSql('ALTER TABLE ingredient ADD CONSTRAINT FK_6BAF7870A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_6BAF7870A76ED395 ON ingredient (user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_ingredient_user_name_key ON ingredient (user_id, name_key)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE app_user RENAME INDEX uniq_user_email TO UNIQ_IDENTIFIER_EMAIL');
        $this->addSql('ALTER TABLE ingredient DROP FOREIGN KEY FK_6BAF7870A76ED395');
        $this->addSql('DROP INDEX IDX_6BAF7870A76ED395 ON ingredient');
        $this->addSql('DROP INDEX uniq_ingredient_user_name_key ON ingredient');
        $this->addSql('ALTER TABLE ingredient DROP user_id');
        $this->addSql('CREATE UNIQUE INDEX uniq_ingredient_name_key ON ingredient (name_key)');
    }
}
