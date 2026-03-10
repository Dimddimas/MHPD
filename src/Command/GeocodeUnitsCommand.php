<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:geocode-units', description: 'Geocodifica market_units via Nominatim')]
class GeocodeUnitsCommand extends Command
{
    public function __construct(private Connection $connection)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $units = $this->connection->fetchAllAssociative(
            "SELECT id, facility_name, social_name, address_line, number, neighborhood, city, state, zipcode
            FROM market_units
            WHERE latitude IS NULL OR longitude IS NULL
            ORDER BY created_at"
        );

        $io->progressStart(count($units));

        foreach ($units as $unit) {
            $query = implode(', ', array_filter([
                $unit['address_line'] . ' ' . $unit['number'],
                $unit['neighborhood'],
                $unit['city'],
                $unit['state'],
                'Brasil',
            ]));

            $url = 'https://nominatim.openstreetmap.org/search?'
                . http_build_query(['q' => $query, 'format' => 'json', 'limit' => 1]);

            $ctx = stream_context_create(['http' => [
                'header' => "User-Agent: MarketHealthDash/1.0\r\n",
                'timeout' => 5,
            ]]);

            $json = @file_get_contents($url, false, $ctx);
            $data = $json ? json_decode($json, true) : [];

            // Fallback: tenta só pelo CEP se não achou pelo endereço
            if (empty($data) && $unit['zipcode']) {
                $url2 = 'https://nominatim.openstreetmap.org/search?'
                    . http_build_query(['postalcode' => $unit['zipcode'], 'country' => 'BR', 'format' => 'json', 'limit' => 1]);
                $json2 = @file_get_contents($url2, false, $ctx);
                $data  = $json2 ? json_decode($json2, true) : [];
            }

            if (!empty($data[0])) {
                $this->connection->executeStatement(
                    "UPDATE market_units SET latitude = :lat, longitude = :lng WHERE id = :id",
                    ['lat' => $data[0]['lat'], 'lng' => $data[0]['lon'], 'id' => $unit['id']]
                );
                $name = $unit['facility_name'] ?? $unit['social_name'] ?? $unit['id'];
                $io->text("✓ {$name} → {$data[0]['lat']}, {$data[0]['lon']}");
            } else {
                $io->warning("✗ Não encontrado: {$unit['facility_name']} — {$query}");
            }

            // Nominatim exige 1 req/segundo
            usleep(1100000);
            $io->progressAdvance();
        }

        $io->progressFinish();
        $io->success('Geocodificação concluída!');
        return Command::SUCCESS;
    }
}