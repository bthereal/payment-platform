<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260825125800 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE customer (id UUID NOT NULL, order_ref VARCHAR(255) NOT NULL, is_synthetic BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, organisation_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_81398E09573471C3 ON customer (order_ref)');
        $this->addSql('CREATE INDEX IDX_81398E099E6B1585 ON customer (organisation_id)');
        $this->addSql('CREATE TABLE payment (id UUID NOT NULL, stripe_id VARCHAR(255) NOT NULL, direction VARCHAR(10) NOT NULL, source VARCHAR(10) NOT NULL, gross_amount INT NOT NULL, stripe_fee INT NOT NULL, application_fee INT NOT NULL, net_amount INT NOT NULL, available_on DATE NOT NULL, order_ref VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, organisation_id UUID NOT NULL, customer_id UUID DEFAULT NULL, related_payment_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_6D28840D3F1B1098 ON payment (stripe_id)');
        $this->addSql('CREATE INDEX IDX_6D28840D9E6B1585 ON payment (organisation_id)');
        $this->addSql('CREATE INDEX IDX_6D28840D9395C3F3 ON payment (customer_id)');
        $this->addSql('CREATE INDEX IDX_6D28840D8E0EAB0B ON payment (related_payment_id)');
        $this->addSql('CREATE INDEX idx_payment_available_on ON payment (available_on)');
        $this->addSql('CREATE INDEX idx_payment_direction ON payment (direction)');
        $this->addSql('CREATE TABLE settlement (id UUID NOT NULL, stripe_id VARCHAR(255) NOT NULL, total_amount INT NOT NULL, status VARCHAR(10) NOT NULL, arrival_date DATE NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, organisation_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_DD9F1B513F1B1098 ON settlement (stripe_id)');
        $this->addSql('CREATE INDEX IDX_DD9F1B519E6B1585 ON settlement (organisation_id)');
        $this->addSql('CREATE TABLE settlement_payment (id UUID NOT NULL, settlement_id UUID NOT NULL, payment_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_78604162C2B9C425 ON settlement_payment (settlement_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_786041624C3A3BB ON settlement_payment (payment_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_settlement_payment ON settlement_payment (settlement_id, payment_id)');
        $this->addSql('ALTER TABLE customer ADD CONSTRAINT FK_81398E099E6B1585 FOREIGN KEY (organisation_id) REFERENCES organisation (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE payment ADD CONSTRAINT FK_6D28840D9E6B1585 FOREIGN KEY (organisation_id) REFERENCES organisation (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE payment ADD CONSTRAINT FK_6D28840D9395C3F3 FOREIGN KEY (customer_id) REFERENCES customer (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE payment ADD CONSTRAINT FK_6D28840D8E0EAB0B FOREIGN KEY (related_payment_id) REFERENCES payment (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE settlement ADD CONSTRAINT FK_DD9F1B519E6B1585 FOREIGN KEY (organisation_id) REFERENCES organisation (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE settlement_payment ADD CONSTRAINT FK_78604162C2B9C425 FOREIGN KEY (settlement_id) REFERENCES settlement (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE settlement_payment ADD CONSTRAINT FK_786041624C3A3BB FOREIGN KEY (payment_id) REFERENCES payment (id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE customer DROP CONSTRAINT FK_81398E099E6B1585');
        $this->addSql('ALTER TABLE payment DROP CONSTRAINT FK_6D28840D9E6B1585');
        $this->addSql('ALTER TABLE payment DROP CONSTRAINT FK_6D28840D9395C3F3');
        $this->addSql('ALTER TABLE payment DROP CONSTRAINT FK_6D28840D8E0EAB0B');
        $this->addSql('ALTER TABLE settlement DROP CONSTRAINT FK_DD9F1B519E6B1585');
        $this->addSql('ALTER TABLE settlement_payment DROP CONSTRAINT FK_78604162C2B9C425');
        $this->addSql('ALTER TABLE settlement_payment DROP CONSTRAINT FK_786041624C3A3BB');
        $this->addSql('DROP TABLE customer');
        $this->addSql('DROP TABLE payment');
        $this->addSql('DROP TABLE settlement');
        $this->addSql('DROP TABLE settlement_payment');
    }
}
