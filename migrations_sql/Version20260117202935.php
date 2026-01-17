<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260117202935 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE assistant_conversation (id INT AUTO_INCREMENT NOT NULL, day DATE NOT NULL, created_at DATETIME NOT NULL, user_id INT NOT NULL, INDEX IDX_5BF3529BA76ED395 (user_id), UNIQUE INDEX uniq_user_day (user_id, day), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE assistant_message (id INT AUTO_INCREMENT NOT NULL, role VARCHAR(20) NOT NULL, content LONGTEXT NOT NULL, payload JSON DEFAULT NULL, created_at DATETIME NOT NULL, conversation_id INT NOT NULL, INDEX IDX_8A36E1EF9AC0396 (conversation_id), INDEX idx_conversation_created (conversation_id, created_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE assistant_conversation ADD CONSTRAINT FK_5BF3529BA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assistant_message ADD CONSTRAINT FK_8A36E1EF9AC0396 FOREIGN KEY (conversation_id) REFERENCES assistant_conversation (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE assistant_conversation DROP FOREIGN KEY FK_5BF3529BA76ED395');
        $this->addSql('ALTER TABLE assistant_message DROP FOREIGN KEY FK_8A36E1EF9AC0396');
        $this->addSql('DROP TABLE assistant_conversation');
        $this->addSql('DROP TABLE assistant_message');
    }
}
