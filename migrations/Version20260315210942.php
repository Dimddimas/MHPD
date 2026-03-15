<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260315210942 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'neutralizada porque latitude e longitude já existem em market_units';
    }

    public function up(Schema $schema): void
    {
    }

    public function down(Schema $schema): void
    {
    }
}