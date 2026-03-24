<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260324000200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add dry_run flag to trades so local dry-run monitoring skips Kraken API calls.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE trades ADD dry_run BOOLEAN NOT NULL DEFAULT 1');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('SQLite column rollback omitted for the dry_run flag.');
    }
}
