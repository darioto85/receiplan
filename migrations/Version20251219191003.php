<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251219191003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        
        $this->addSql('ALTER INDEX uniq_identifier_email RENAME TO uniq_user_email');
        $this->addSql('ALTER TABLE ingredient ADD user_id INT NOT NULL');
        $this->addSql('ALTER TABLE ingredient ADD CONSTRAINT FK_6BAF7870A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_6BAF7870A76ED395 ON ingredient (user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_ingredient_user_name_key ON ingredient (user_id, name_key)');
        $this->addSql('ALTER TABLE user_ingredient DROP CONSTRAINT fk_ccc8be9c933fe08c');
        $this->addSql('ALTER TABLE user_ingredient ALTER quantity SET DEFAULT 0');
        $this->addSql('ALTER TABLE user_ingredient ADD CONSTRAINT FK_CCC8BE9C933FE08C FOREIGN KEY (ingredient_id) REFERENCES ingredient (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER INDEX uniq_user_email RENAME TO uniq_identifier_email');
        $this->addSql('ALTER TABLE ingredient DROP CONSTRAINT FK_6BAF7870A76ED395');
        $this->addSql('DROP INDEX IDX_6BAF7870A76ED395');
        $this->addSql('DROP INDEX uniq_ingredient_user_name_key');
        $this->addSql('ALTER TABLE ingredient DROP user_id');
        $this->addSql('CREATE UNIQUE INDEX uniq_ingredient_name_key ON ingredient (name_key)');
        $this->addSql('ALTER TABLE user_ingredient DROP CONSTRAINT FK_CCC8BE9C933FE08C');
        $this->addSql('ALTER TABLE user_ingredient ALTER quantity DROP DEFAULT');
        $this->addSql('ALTER TABLE user_ingredient ADD CONSTRAINT fk_ccc8be9c933fe08c FOREIGN KEY (ingredient_id) REFERENCES ingredient (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }
}
