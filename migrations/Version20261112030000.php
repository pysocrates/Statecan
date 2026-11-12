<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261112030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Agent identities, sessions, fenced leases, receipt attempts and explicit operator roles';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE agent (id VARCHAR(36) NOT NULL, name VARCHAR(160) NOT NULL, token_hash VARCHAR(64) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_268B9C9DB3BC57DA ON agent (token_hash)');
        $this->addSql('CREATE TABLE agent_operation (id VARCHAR(36) NOT NULL, request_key VARCHAR(128) NOT NULL, fingerprint VARCHAR(64) NOT NULL, response JSONB NOT NULL, agent_id VARCHAR(36) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX agent_operation_key ON agent_operation (agent_id, request_key)');
        $this->addSql('CREATE INDEX IDX_EED520293414710B ON agent_operation (agent_id)');
        $this->addSql('CREATE TABLE agent_session (id VARCHAR(36) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_heartbeat_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, request_key VARCHAR(128) NOT NULL, agent_id VARCHAR(36) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX session_registration_key ON agent_session (agent_id, request_key)');
        $this->addSql('CREATE INDEX IDX_217B77E3414710B ON agent_session (agent_id)');
        $this->addSql('CREATE TABLE completion_receipt (id VARCHAR(36) NOT NULL, status VARCHAR(24) NOT NULL, hash VARCHAR(64) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, canonical_content TEXT NOT NULL, lease_generation INT NOT NULL, contract_version INT NOT NULL, request_key VARCHAR(128) NOT NULL, task_id VARCHAR(36) NOT NULL, session_id VARCHAR(36) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX receipt_request_key ON completion_receipt (task_id, request_key)');
        $this->addSql('CREATE INDEX IDX_A6F943338DB60186 ON completion_receipt (task_id)');
        $this->addSql('CREATE INDEX IDX_A6F94333613FECDF ON completion_receipt (session_id)');
        $this->addSql('CREATE TABLE task_lease (token_hash VARCHAR(64) NOT NULL, generation INT NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, request_key VARCHAR(128) NOT NULL, task_id VARCHAR(36) NOT NULL, session_id VARCHAR(36) NOT NULL, PRIMARY KEY (task_id))');
        $this->addSql('CREATE INDEX IDX_21636FB5F9D83E2 ON task_lease (expires_at)');
        $this->addSql('CREATE INDEX IDX_21636FB5613FECDF ON task_lease (session_id)');
        $this->addSql('ALTER TABLE agent_operation ADD CONSTRAINT FK_EED520293414710B FOREIGN KEY (agent_id) REFERENCES agent (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE agent_session ADD CONSTRAINT FK_217B77E3414710B FOREIGN KEY (agent_id) REFERENCES agent (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE completion_receipt ADD CONSTRAINT FK_A6F943338DB60186 FOREIGN KEY (task_id) REFERENCES task (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE completion_receipt ADD CONSTRAINT FK_A6F94333613FECDF FOREIGN KEY (session_id) REFERENCES agent_session (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE task_lease ADD CONSTRAINT FK_21636FB58DB60186 FOREIGN KEY (task_id) REFERENCES task (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE task_lease ADD CONSTRAINT FK_21636FB5613FECDF FOREIGN KEY (session_id) REFERENCES agent_session (id) NOT DEFERRABLE');
        $this->addSql("ALTER TABLE app_user ADD roles JSON DEFAULT '[]' NOT NULL");
        $this->addSql('ALTER TABLE app_user ALTER roles DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE agent_operation DROP CONSTRAINT FK_EED520293414710B');
        $this->addSql('ALTER TABLE agent_session DROP CONSTRAINT FK_217B77E3414710B');
        $this->addSql('ALTER TABLE completion_receipt DROP CONSTRAINT FK_A6F943338DB60186');
        $this->addSql('ALTER TABLE completion_receipt DROP CONSTRAINT FK_A6F94333613FECDF');
        $this->addSql('ALTER TABLE task_lease DROP CONSTRAINT FK_21636FB58DB60186');
        $this->addSql('ALTER TABLE task_lease DROP CONSTRAINT FK_21636FB5613FECDF');
        $this->addSql('DROP TABLE agent');
        $this->addSql('DROP TABLE agent_operation');
        $this->addSql('DROP TABLE agent_session');
        $this->addSql('DROP TABLE completion_receipt');
        $this->addSql('DROP TABLE task_lease');
        $this->addSql('ALTER TABLE app_user DROP roles');
    }
}
