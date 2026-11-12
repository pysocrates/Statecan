<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261111010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Transactional task core, versioned contracts, append-only ledger and outbox';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform, 'The transactional core requires PostgreSQL.');
        $this->addSql('CREATE TABLE api_mutation (response JSONB NOT NULL, status INT NOT NULL, id VARCHAR(64) NOT NULL, request_hash VARCHAR(64) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE outbox_event (id VARCHAR(36) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, published_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, type VARCHAR(100) NOT NULL, aggregate_id VARCHAR(36) NOT NULL, payload JSONB NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_F5492CA4E0D4FDE18B8E8428 ON outbox_event (published_at, created_at)');
        $this->addSql('CREATE TABLE project (id VARCHAR(36) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, idempotency_key VARCHAR(128) DEFAULT NULL, request_hash VARCHAR(255) DEFAULT NULL, archived_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, name VARCHAR(200) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_2FB3D0EE7FD1C147 ON project (idempotency_key)');
        $this->addSql('CREATE TABLE state_event (id VARCHAR(36) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, from_state VARCHAR(40) DEFAULT NULL, to_state VARCHAR(40) NOT NULL, version INT NOT NULL, actor_id VARCHAR(180) NOT NULL, metadata JSONB NOT NULL, idempotency_key VARCHAR(128) DEFAULT NULL, task_id VARCHAR(36) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_493D8EAB7FD1C147 ON state_event (idempotency_key)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_493D8EAB8DB60186BF1CD3C3 ON state_event (task_id, version)');
        $this->addSql('CREATE INDEX IDX_493D8EAB8DB60186 ON state_event (task_id)');
        $this->addSql('CREATE TABLE task (id VARCHAR(36) NOT NULL, status VARCHAR(40) NOT NULL, version INT NOT NULL, contract_version INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, idempotency_key VARCHAR(128) DEFAULT NULL, request_hash VARCHAR(255) DEFAULT NULL, title VARCHAR(200) NOT NULL, project_id VARCHAR(36) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_527EDB257FD1C147 ON task (idempotency_key)');
        $this->addSql('CREATE INDEX IDX_527EDB25166D1F9C7B00651C ON task (project_id, status)');
        $this->addSql('CREATE INDEX IDX_527EDB25166D1F9C ON task (project_id)');
        $this->addSql('CREATE TABLE task_contract (id VARCHAR(36) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, version INT NOT NULL, document JSONB NOT NULL, task_id VARCHAR(36) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_A94FC2158DB60186BF1CD3C3 ON task_contract (task_id, version)');
        $this->addSql('CREATE INDEX IDX_A94FC2158DB60186 ON task_contract (task_id)');
        $this->addSql('CREATE TABLE task_dependency (id VARCHAR(36) NOT NULL, task_id VARCHAR(36) NOT NULL, depends_on_id VARCHAR(36) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_334B1EAA8DB601861E088F8 ON task_dependency (task_id, depends_on_id)');
        $this->addSql('CREATE INDEX IDX_334B1EAA8DB60186 ON task_dependency (task_id)');
        $this->addSql('CREATE INDEX IDX_334B1EAA1E088F8 ON task_dependency (depends_on_id)');
        $this->addSql('ALTER TABLE state_event ADD CONSTRAINT FK_493D8EAB8DB60186 FOREIGN KEY (task_id) REFERENCES task (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE task ADD CONSTRAINT FK_527EDB25166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE task_contract ADD CONSTRAINT FK_A94FC2158DB60186 FOREIGN KEY (task_id) REFERENCES task (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE task_dependency ADD CONSTRAINT FK_334B1EAA8DB60186 FOREIGN KEY (task_id) REFERENCES task (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE task_dependency ADD CONSTRAINT FK_334B1EAA1E088F8 FOREIGN KEY (depends_on_id) REFERENCES task (id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE state_event DROP CONSTRAINT FK_493D8EAB8DB60186');
        $this->addSql('ALTER TABLE task DROP CONSTRAINT FK_527EDB25166D1F9C');
        $this->addSql('ALTER TABLE task_contract DROP CONSTRAINT FK_A94FC2158DB60186');
        $this->addSql('ALTER TABLE task_dependency DROP CONSTRAINT FK_334B1EAA8DB60186');
        $this->addSql('ALTER TABLE task_dependency DROP CONSTRAINT FK_334B1EAA1E088F8');
        $this->addSql('DROP TABLE api_mutation');
        $this->addSql('DROP TABLE outbox_event');
        $this->addSql('DROP TABLE project');
        $this->addSql('DROP TABLE state_event');
        $this->addSql('DROP TABLE task');
        $this->addSql('DROP TABLE task_contract');
        $this->addSql('DROP TABLE task_dependency');
    }
}
