<?php

namespace App\Service\Agent\Tool;

use Doctrine\DBAL\Connection;

/**
 * Consultas de estatísticas gerais do mercado:
 * - quantas unidades monitoradas em um período
 * - quantos snapshots coletados
 * - resumo de cobertura por exame
 * Muito mais eficiente que varrer market_price_snapshots para perguntas de contagem.
 */
class QueryMarketStatsTool implements ToolInterface
{
    public function __construct(private readonly Connection $connection) {}

    public function getName(): string { return 'query_market_stats'; }

    public function getDefinition(): array
    {
        return [
            'name'        => $this->getName(),
            'description' => <<<DESC
            Retorna estatísticas gerais do mercado monitorado: quantas unidades, quantos exames,
            quantos preços coletados, cobertura por período.
            Use quando o usuário perguntar:
            - "Quantas unidades monitoradas esta semana/mês/hoje?"
            - "Quantos exames estamos acompanhando?"
            - "Quantos preços foram coletados?"
            - "Qual a cobertura de dados do sistema?"
            DESC,
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'date_from' => [
                        'type'        => 'string',
                        'description' => 'Data inicial YYYY-MM-DD. Padrão: 7 dias atrás.',
                    ],
                    'date_to' => [
                        'type'        => 'string',
                        'description' => 'Data final YYYY-MM-DD. Padrão: data mais recente disponível.',
                    ],
                ],
                'required' => [],
            ],
        ];
    }

    public function execute(array $input): array
    {
        $latestDate = $this->connection->fetchOne(
            "SELECT MAX(DATE(collected_at)) FROM market_price_snapshots"
        ) ?: date('Y-m-d');

        $dateTo   = $input['date_to']   ?? $latestDate;
        $dateFrom = $input['date_from'] ?? date('Y-m-d', strtotime($dateTo . ' -7 days'));

        $stats = $this->connection->fetchAssociative(<<<SQL
            SELECT
                COUNT(DISTINCT unit_id)      AS total_unidades,
                COUNT(DISTINCT procedure_id) AS total_exames,
                COUNT(*)                     AS total_snapshots,
                MIN(DATE(collected_at))      AS data_inicio,
                MAX(DATE(collected_at))      AS data_fim,
                COUNT(DISTINCT DATE(collected_at)) AS dias_com_dados
            FROM market_price_snapshots
            WHERE DATE(collected_at) BETWEEN :df AND :dt
        SQL, ['df' => $dateFrom, 'dt' => $dateTo]);

        // Distribuição por dia
        $byDay = $this->connection->fetchAllAssociative(<<<SQL
            SELECT
                DATE(collected_at)           AS dia,
                COUNT(DISTINCT unit_id)      AS unidades,
                COUNT(DISTINCT procedure_id) AS exames,
                COUNT(*)                     AS snapshots
            FROM market_price_snapshots
            WHERE DATE(collected_at) BETWEEN :df AND :dt
            GROUP BY DATE(collected_at)
            ORDER BY dia DESC
            LIMIT 14
        SQL, ['df' => $dateFrom, 'dt' => $dateTo]);

        return [
            'success'    => true,
            'periodo'    => ['de' => $dateFrom, 'ate' => $dateTo],
            'resumo'     => $stats,
            'por_dia'    => $byDay,
        ];
    }
}