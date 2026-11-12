<?php

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261112040000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Local Git repositories, observed activity and task correlation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE git_repository (id VARCHAR(36) NOT NULL, path VARCHAR(1024) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, refs JSONB NOT NULL, observed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, project_id VARCHAR(36) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX git_repository_path ON git_repository (path)');
        $this->addSql('CREATE INDEX IDX_C2B3204A166D1F9C ON git_repository (project_id)');
        $this->addSql('CREATE TABLE git_event (id VARCHAR(36) NOT NULL, event_key VARCHAR(255) NOT NULL, kind VARCHAR(32) NOT NULL, sha VARCHAR(64) DEFAULT NULL, metadata JSONB NOT NULL, observed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, repository_id VARCHAR(36) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX git_event_identity ON git_event (repository_id, event_key)');
        $this->addSql('CREATE INDEX IDX_9DCF0F350C9D4F7 ON git_event (repository_id)');
        $this->addSql('CREATE TABLE git_task_association (id VARCHAR(36) NOT NULL, sha VARCHAR(64) NOT NULL, session_id VARCHAR(36) DEFAULT NULL, agent_id VARCHAR(36) DEFAULT NULL, source VARCHAR(32) NOT NULL, confidence VARCHAR(32) NOT NULL, verification_status VARCHAR(32) NOT NULL, observed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, repository_id VARCHAR(36) NOT NULL, task_id VARCHAR(36) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX git_association_identity ON git_task_association (repository_id, sha, task_id)');
        $this->addSql('CREATE INDEX IDX_6D44815B50C9D4F7 ON git_task_association (repository_id)');
        $this->addSql('CREATE INDEX IDX_6D44815B8DB60186 ON git_task_association (task_id)');
        $this->addSql('ALTER TABLE git_repository ADD CONSTRAINT FK_C2B3204A166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE git_event ADD CONSTRAINT FK_9DCF0F350C9D4F7 FOREIGN KEY (repository_id) REFERENCES git_repository (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE git_task_association ADD CONSTRAINT FK_6D44815B50C9D4F7 FOREIGN KEY (repository_id) REFERENCES git_repository (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE git_task_association ADD CONSTRAINT FK_6D44815B8DB60186 FOREIGN KEY (task_id) REFERENCES task (id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE git_task_association');
        $this->addSql('DROP TABLE git_event');
        $this->addSql('DROP TABLE git_repository');
    }
}
