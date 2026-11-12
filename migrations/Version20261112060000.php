<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261112060000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Worker lifecycle heartbeats and job ownership for stalled-work detection';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE worker_process (id VARCHAR(36) NOT NULL, hostname VARCHAR(255) NOT NULL, pid INT NOT NULL, transports JSONB NOT NULL, started_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, last_seen_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, last_progress_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, stopped_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, current_job_id VARCHAR(128) DEFAULT NULL, handled_count INT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TRIGGER statecan_dashboard_changed AFTER INSERT OR UPDATE OR DELETE OR TRUNCATE ON worker_process FOR EACH STATEMENT EXECUTE FUNCTION statecan_notify_dashboard()');
        $this->addSql('ALTER TABLE background_job ADD worker_id VARCHAR(36) DEFAULT NULL');
        $this->addSql('ALTER TABLE background_job ADD queue_name VARCHAR(190) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE worker_process');
        $this->addSql('ALTER TABLE background_job DROP worker_id');
        $this->addSql('ALTER TABLE background_job DROP queue_name');
    }
}
