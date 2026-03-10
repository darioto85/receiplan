<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260310203229 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE assistant_run (id INT AUTO_INCREMENT NOT NULL, status VARCHAR(255) NOT NULL, is_active TINYINT DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, closed_at DATETIME DEFAULT NULL, conversation_id INT NOT NULL, INDEX IDX_BF7603D9AC0396 (conversation_id), INDEX idx_assistant_run_active (is_active), INDEX idx_assistant_run_status (status), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE assistant_run_action (id INT AUTO_INCREMENT NOT NULL, client_action_id VARCHAR(50) NOT NULL, type VARCHAR(255) NOT NULL, status VARCHAR(255) NOT NULL, data JSON NOT NULL, missing JSON NOT NULL, execution_order INT DEFAULT 100 NOT NULL, result JSON DEFAULT NULL, error JSON DEFAULT NULL, executed_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, run_id INT NOT NULL, INDEX IDX_52AAAB9584E3FEC4 (run_id), INDEX idx_assistant_run_action_type (type), INDEX idx_assistant_run_action_status (status), UNIQUE INDEX uniq_run_client_action (run_id, client_action_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE assistant_run ADD CONSTRAINT FK_BF7603D9AC0396 FOREIGN KEY (conversation_id) REFERENCES assistant_conversation (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assistant_run_action ADD CONSTRAINT FK_52AAAB9584E3FEC4 FOREIGN KEY (run_id) REFERENCES assistant_run (id) ON DELETE CASCADE');
        $this->addSql('DROP INDEX uniq_user_day ON assistant_conversation');
        $this->addSql('ALTER TABLE assistant_conversation ADD updated_at DATETIME NOT NULL, DROP day');
        $this->addSql('ALTER TABLE assistant_conversation RENAME INDEX idx_5bf3529ba76ed395 TO idx_assistant_conversation_user');
        $this->addSql('DROP INDEX idx_conversation_created ON assistant_message');
        $this->addSql('ALTER TABLE assistant_message ADD run_id INT DEFAULT NULL, CHANGE role role VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE assistant_message ADD CONSTRAINT FK_8A36E1EF84E3FEC4 FOREIGN KEY (run_id) REFERENCES assistant_run (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_8A36E1EF84E3FEC4 ON assistant_message (run_id)');
        $this->addSql('CREATE INDEX idx_assistant_message_role ON assistant_message (role)');
        $this->addSql('CREATE INDEX idx_assistant_message_created_at ON assistant_message (created_at)');
    }

    public function down(Schema $schema): void
    {
        // assistant_message d'abord, car il référence assistant_run
        $this->addSql('ALTER TABLE assistant_message DROP FOREIGN KEY FK_8A36E1EF84E3FEC4');
        $this->addSql('DROP INDEX IDX_8A36E1EF84E3FEC4 ON assistant_message');
        $this->addSql('DROP INDEX idx_assistant_message_role ON assistant_message');
        $this->addSql('DROP INDEX idx_assistant_message_created_at ON assistant_message');
        $this->addSql('ALTER TABLE assistant_message DROP run_id, CHANGE role role VARCHAR(20) NOT NULL');
        $this->addSql('CREATE INDEX idx_conversation_created ON assistant_message (conversation_id, created_at)');

        // puis tables run/action
        $this->addSql('ALTER TABLE assistant_run_action DROP FOREIGN KEY FK_52AAAB9584E3FEC4');
        $this->addSql('ALTER TABLE assistant_run DROP FOREIGN KEY FK_BF7603D9AC0396');
        $this->addSql('DROP TABLE assistant_run_action');
        $this->addSql('DROP TABLE assistant_run');

        // assistant_conversation ensuite
        $this->addSql('ALTER TABLE assistant_conversation ADD day DATE DEFAULT NULL');
        $this->addSql('UPDATE assistant_conversation SET day = DATE(created_at) WHERE day IS NULL');
        $this->addSql('ALTER TABLE assistant_conversation MODIFY day DATE NOT NULL');
        $this->addSql('ALTER TABLE assistant_conversation DROP updated_at');
        $this->addSql('CREATE UNIQUE INDEX uniq_user_day ON assistant_conversation (user_id, day)');
        $this->addSql('ALTER TABLE assistant_conversation RENAME INDEX idx_assistant_conversation_user TO IDX_5BF3529BA76ED395');
    }   
}
