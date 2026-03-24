<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260324000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the trades table used by the Kraken Futures trade manager.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE trades (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            symbol VARCHAR(32) NOT NULL,
            side VARCHAR(255) NOT NULL,
            entry_price NUMERIC(18, 8) NOT NULL,
            size NUMERIC(18, 8) NOT NULL,
            stop_loss_price NUMERIC(18, 8) NOT NULL,
            tp1_price NUMERIC(18, 8) NOT NULL,
            tp2_price NUMERIC(18, 8) NOT NULL,
            status VARCHAR(255) NOT NULL,
            tp1_filled BOOLEAN NOT NULL,
            break_even_moved BOOLEAN NOT NULL,
            kraken_order_ids CLOB NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        )');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE trades');
    }
}
