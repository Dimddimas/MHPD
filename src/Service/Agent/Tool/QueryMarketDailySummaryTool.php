<?php

namespace App\Service\Agent\Tool;

use Doctrine\DBAL\Connection;

/**
 * Consulta evolução de preços ao longo do tempo.
 * Usa market_price_snapshots diretamente (mais confiável que a view).
 * Limitada a 200 registros para não sobrecarregar.
 */
class QueryMarketDailySummaryTool implements ToolInterface
{
    public function __construct(private readonly Connection $connection) {}

    public function getName(): string { return 'query_market_daily_summary'; }

    public function getDefinition(): array
    {
        return [
            'name'        => $this->getName(),
            'description' => <<<DESC
            Consulta a evolução de preços de exames laboratoriais ao longo do tempo.
            Retorna: procedure_name, tuss_code, collected_date, min_price, avg_price, max_price, total_units.
            Use quando precisar de:
            - Tendências e variações de preço ao longo de dias/semanas
            - Preço médio, mínimo e máximo de exames por período
            - Quais exames são mais caros ou mais baratos em média
            - Evolução de um exame específico nos últimos dias
            - Resumo geral do mercado (sem filtrar por exame)
            DESC,
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'date_from' => [
                        'type'        => 'string',
                        'description' => 'Data inicial YYYY-MM-DD. Padrão: 30 dias atrás.',
                    ],
                    'date_to' => [
                        'type'        => 'string',
                        'description' => 'Data final YYYY-MM-DD. Padrão: data mais recente.',
                    ],
                    'procedure_ids' => [
                        'type'        => 'array',
                        'items'       => ['type' => 'string'],
                        'description' => 'UUIDs de procedimentos para filtrar. Omita para retornar todos.',
                    ],
                    'limit' => [
                        'type'        => 'integer',
                        'description' => 'Máximo de registros. Padrão: 50, máximo: 200.',
                        'default'     => 50,
                    ],
                ],
                'required' => [],
            ],
        ];
    }

    public function execute(array $input): array
    {
        $limit    = min((int)($input['limit'] ?? 50), 200);
        $dateTo   = $input['date_to']   ?? $this->getLatestDate();
        $dateFrom = $input['date_from'] ?? date('Y-m-d', strtotime($dateTo . ' -30 days'));

        $procedureIds = $input['procedure_ids'] ?? [];

        // Agrupa por procedure + dia usando market_price_snapshots
        $sql = <<<SQL
            SELECT
                mp.name                                         AS procedure_name,
                mp.tuss_code,
                DATE(mps.collected_at)                         AS collected_date,
                ROUND(MIN(mps.price)::numeric, 2)              AS min_price,
                ROUND(AVG(mps.price)::numeric, 2)              AS avg_price,
                ROUND(MAX(mps.price)::numeric, 2)              AS max_price,
                COUNT(DISTINCT mps.unit_id)                    AS total_units,
                CASE WHEN MIN(mps.price) > 0
                    THEN ROUND(((MAX(mps.price) - MIN(mps.price)) / MIN(mps.price) * 100)::numeric, 1)
                    ELSE 0 END                                  AS dispersion_pct
            FROM market_price_snapshots mps
            JOIN market_procedures mp ON mp.id = mps.procedure_id
            WHERE DATE(mps.collected_at) BETWEEN :df AND :dt
        SQL;

        $params = ['df' => $dateFrom, 'dt' => $dateTo];

        if (!empty($procedureIds)) {
            // Build IN clause safely
            $placeholders = implode(',', array_map(
                fn($i) => ':pid' . $i, array_keys($procedureIds)
            ));
            $sql .= " AND mps.procedure_id IN ({$placeholders})";
            foreach ($procedureIds as $i => $pid) {
                $params['pid' . $i] = $pid;
            }
        }

        $sql .= " GROUP BY mp.name, mp.tuss_code, DATE(mps.collected_at)
                  ORDER BY collected_date DESC, avg_price DESC
                  LIMIT :lim";
        $params['lim'] = $limit;

        $rows = $this->connection->fetchAllAssociative($sql, $params);

        // Estatísticas gerais
        $avgPrices = array_map(fn($r) => (float)$r['avg_price'], $rows);
        $stats     = [];
        if (!empty($avgPrices)) {
            $stats = [
                'periodo'       => $dateFrom . ' a ' . $dateTo,
                'total_registros' => count($rows),
                'preco_medio_geral' => round(array_sum($avgPrices) / count($avgPrices), 2),
                'preco_minimo'  => round(min($avgPrices), 2),
                'preco_maximo'  => round(max($avgPrices), 2),
            ];
        }

        return [
            'success'    => true,
            'count'      => count($rows),
            'statistics' => $stats,
            'data'       => $rows,
        ];
    }

    private function getLatestDate(): string
    {
        return $this->connection->fetchOne(
            "SELECT MAX(DATE(collected_at)) FROM market_price_snapshots"
        ) ?: date('Y-m-d');
    }
}