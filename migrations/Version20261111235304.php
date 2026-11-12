<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261111235304 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        if ($this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform) {
            $this->addSql('ALTER INDEX uniq_identifier_email RENAME TO uniq_88bdf3e9e7927c74');
            return;
        }
        $this->addSql('ALTER TABLE app_user RENAME INDEX uniq_identifier_email TO UNIQ_88BDF3E9E7927C74');
    }

    public function down(Schema $schema): void
    {
        if ($this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform) {
            $this->addSql('ALTER INDEX uniq_88bdf3e9e7927c74 RENAME TO uniq_identifier_email');
            return;
        }
        $this->addSql('ALTER TABLE app_user RENAME INDEX uniq_88bdf3e9e7927c74 TO UNIQ_IDENTIFIER_EMAIL');
    }
}
