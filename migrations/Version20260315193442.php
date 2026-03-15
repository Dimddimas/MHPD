<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260315193442 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP SEQUENCE market_price_snapshots_id_seq_new CASCADE');
        $this->addSql('DROP INDEX uniq_snapshot_daily');
        $this->addSql('ALTER TABLE market_price_snapshots DROP collected_date');
        $this->addSql('ALTER TABLE market_price_snapshots ALTER id DROP DEFAULT');
        $this->addSql('ALTER TABLE market_units DROP latitude');
        $this->addSql('ALTER TABLE market_units DROP longitude');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SEQUENCE market_price_snapshots_id_seq_new INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('ALTER TABLE market_price_snapshots ADD collected_date DATE DEFAULT CURRENT_DATE NOT NULL');
        $this->addSql('ALTER TABLE market_price_snapshots ALTER id SET DEFAULT nextval(\'market_price_snapshots_id_seq_new\'::regclass)');
        $this->addSql('CREATE UNIQUE INDEX uniq_snapshot_daily ON market_price_snapshots (unit_id, procedure_id, collected_date)');
        $this->addSql('ALTER TABLE market_units ADD latitude DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE market_units ADD longitude DOUBLE PRECISION DEFAULT NULL');
    }
}
