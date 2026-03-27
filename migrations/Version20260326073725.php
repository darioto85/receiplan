<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260326073725 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE user_transaction (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(100) NOT NULL, status VARCHAR(50) DEFAULT NULL, amount INT DEFAULT NULL, currency VARCHAR(10) DEFAULT NULL, stripe_customer_id VARCHAR(255) DEFAULT NULL, stripe_subscription_id VARCHAR(255) DEFAULT NULL, stripe_checkout_session_id VARCHAR(255) DEFAULT NULL, stripe_invoice_id VARCHAR(255) DEFAULT NULL, stripe_payment_intent_id VARCHAR(255) DEFAULT NULL, stripe_event_id VARCHAR(255) DEFAULT NULL, payload JSON DEFAULT NULL, occurred_at DATETIME NOT NULL, created_at DATETIME NOT NULL, user_id INT NOT NULL, INDEX idx_user_transaction_user (user_id), INDEX idx_user_transaction_type (type), INDEX idx_user_transaction_created_at (created_at), UNIQUE INDEX uniq_stripe_event_id (stripe_event_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE user_transaction ADD CONSTRAINT FK_DB2CCC44A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE user_transaction DROP FOREIGN KEY FK_DB2CCC44A76ED395');
        $this->addSql('DROP TABLE user_transaction');
    }
}
