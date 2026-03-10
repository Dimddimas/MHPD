<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260310013252 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE market_units ADD COLUMN latitude DOUBLE PRECISION NULL');
        $this->addSql('ALTER TABLE market_units ADD COLUMN longitude DOUBLE PRECISION NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE market_units DROP COLUMN latitude');
        $this->addSql('ALTER TABLE market_units DROP COLUMN longitude');
    }
}
