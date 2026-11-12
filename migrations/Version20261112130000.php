<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261112130000 extends AbstractMigration
{
    private const ENTITIES = ['project', 'task', 'task_contract', 'task_dependency', 'state_event', 'agent', 'agent_session', 'task_lease', 'completion_receipt', 'git_repository', 'git_event', 'git_task_association'];

    public function getDescription(): string
    {
        return 'Publish bounded entity revisions for targeted console refreshes';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform, 'Console notifications require PostgreSQL.');
        $this->addSql(<<<'SQL'
CREATE OR REPLACE FUNCTION statecan_notify_dashboard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP <> 'TRUNCATE' AND TG_TABLE_NAME IN ('project', 'task', 'task_contract', 'task_dependency', 'state_event', 'agent', 'agent_session', 'task_lease', 'completion_receipt', 'git_repository', 'git_event', 'git_task_association') THEN
        RETURN NULL;
    END IF;
    PERFORM pg_notify('statecan_dashboard', json_build_object('table', TG_TABLE_NAME, 'operation', TG_OP)::text);
    RETURN NULL;
END;
$$
SQL);
        $this->addSql(<<<'SQL'
CREATE FUNCTION statecan_notify_entity() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    item jsonb;
    project_id text;
    task_id text;
BEGIN
    item := CASE WHEN TG_OP = 'DELETE' THEN to_jsonb(OLD) ELSE to_jsonb(NEW) END;
    task_id := CASE WHEN TG_TABLE_NAME = 'task' THEN item->>'id' ELSE item->>'task_id' END;
    project_id := CASE WHEN TG_TABLE_NAME = 'project' THEN item->>'id' ELSE item->>'project_id' END;
    IF project_id IS NULL AND task_id IS NOT NULL THEN
        SELECT t.project_id::text INTO project_id FROM task t WHERE t.id = task_id;
    END IF;
    PERFORM pg_notify('statecan_dashboard', json_strip_nulls(json_build_object(
        'table', TG_TABLE_NAME, 'operation', TG_OP,
        'id', coalesce(item->>'id', item->>'task_id'),
        'projectId', project_id, 'taskId', task_id, 'sessionId', item->>'session_id',
        'revision', md5(item::text)
    ))::text);
    RETURN NULL;
END;
$$
SQL);
        foreach (self::ENTITIES as $table) {
            $this->addSql(sprintf('CREATE TRIGGER statecan_console_entity_changed AFTER INSERT OR UPDATE OR DELETE ON %s FOR EACH ROW EXECUTE FUNCTION statecan_notify_entity()', $table));
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::ENTITIES as $table) {
            $this->addSql(sprintf('DROP TRIGGER statecan_console_entity_changed ON %s', $table));
        }
        $this->addSql('DROP FUNCTION statecan_notify_entity()');
        $this->addSql(<<<'SQL'
CREATE OR REPLACE FUNCTION statecan_notify_dashboard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    PERFORM pg_notify('statecan_dashboard', '');
    RETURN NULL;
END;
$$
SQL);
    }
}
