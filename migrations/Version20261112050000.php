<?php

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261112050000 extends AbstractMigration
{
    private const TABLES = [
        'project', 'task', 'task_contract', 'task_dependency', 'state_event',
        'agent', 'agent_session', 'task_lease', 'completion_receipt',
        'git_repository', 'git_event', 'git_task_association',
        'background_job', 'outbox_event', 'messenger_messages',
    ];

    public function getDescription(): string
    {
        return 'Publish commit-time dashboard invalidations through PostgreSQL NOTIFY';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform, 'Dashboard notifications require PostgreSQL.');
        $this->addSql(<<<'SQL'
CREATE FUNCTION statecan_notify_dashboard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    PERFORM pg_notify('statecan_dashboard', '');
    RETURN NULL;
END;
$$
SQL);
        foreach (self::TABLES as $table) {
            $this->addSql(sprintf('CREATE TRIGGER statecan_dashboard_changed AFTER INSERT OR UPDATE OR DELETE OR TRUNCATE ON %s FOR EACH STATEMENT EXECUTE FUNCTION statecan_notify_dashboard()', $table));
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::TABLES as $table) {
            $this->addSql(sprintf('DROP TRIGGER statecan_dashboard_changed ON %s', $table));
        }
        $this->addSql('DROP FUNCTION statecan_notify_dashboard()');
    }
}
