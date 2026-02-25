<?php

namespace App\Service\Agent\Tool;

use Doctrine\DBAL\Connection;

/**
 * Consulta direta em market_price_snapshots para análises que precisam de
 * dados granulares: dispersão, ranking por dia, quem cobra mais/menos, etc.
 * Limitada a 200 registros para evitar sobrecarga.
 */
class QueryPriceSnapshotsTool implements ToolInterface
{
    public function __construct(private readonly Connection $connection) {}

    public function getName(): string { return 'query_price_snapshots'; }

    public function getDefinition(): array
    {
        return [
            'name'        => $this->getName(),
            'description' => <<<DESC
            Consulta market_price_snapshots para análises granulares de preço.
            Use quando precisar de:
            - Ranking de unidades por preço (quem cobra mais/menos caro)
            - Dispersão de preços de um exame em uma data
            - Comparar preços entre unidades para um procedimento específico
            - Identificar dumping (preço muito abaixo da média)
            - Ver quais unidades lideram em preço mínimo
            Retorna: procedure_name, unit_name, city, price, collected_date, dispersao_pct.
            IMPORTANTE: sempre filtre por procedure_id e uma data específica para resultados úteis.
            DESC,
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'procedure_id' => [
                        'type'        => 'string',
                        'description' => 'UUID do procedimento. Use list_procedures para obter o ID.',
                    ],
                    'date' => [
                        'type'        => 'string',
                        'description' => 'Data no formato YYYY-MM-DD. Se omitido, usa a data mais recente disponível.',
                    ],
                    'order_by' => [
                        'type'        => 'string',
                        'enum'        => ['price_asc', 'price_desc', 'dispersion_desc'],
                        'description' => 'Ordenação: price_asc (mais barato), price_desc (mais caro), dispersion_desc (maior dispersão).',
                        'default'     => 'price_asc',
                    ],
                    'limit' => [
                        'type'        => 'integer',
                        'description' => 'Máximo de registros. Padrão: 30, máximo: 100.',
                        'default'     => 30,
                    ],
                ],
                'required' => ['procedure_id'],
            ],
        ];
    }

    public function execute(array $input): array
    {
        $procedureId = $input['procedure_id'] ?? null;
        if (!$procedureId) {
            return ['success' => false, 'error' => 'procedure_id é obrigatório.'];
        }

        $limit = min((int)($input['limit'] ?? 30), 100);

        // Data: usa a fornecida ou a mais recente disponível
        if (!empty($input['date'])) {
            $date = $input['date'];
        } else {
            $date = $this->connection->fetchOne(
                "SELECT MAX(DATE(collected_at)) FROM market_price_snapshots WHERE procedure_id = :pid",
                ['pid' => $procedureId]
            ) ?: date('Y-m-d');
        }

        $orderSql = match($input['order_by'] ?? 'price_asc') {
            'price_desc'       => 'avg_price DESC',
            'dispersion_desc'  => 'dispersion_pct DESC',
            default            => 'avg_price ASC',
        };

        $rows = $this->connection->fetchAllAssociative(<<<SQL
            WITH summary AS (
                SELECT
                    mps.unit_id,
                    ROUND(AVG(mps.price)::numeric, 2)   AS avg_price,
                    ROUND(MIN(mps.price)::numeric, 2)   AS min_price,
                    ROUND(MAX(mps.price)::numeric, 2)   AS max_price
                FROM market_price_snapshots mps
                WHERE mps.procedure_id = :pid
                  AND DATE(mps.collected_at) = :date
                GROUP BY mps.unit_id
            ),
            mkt AS (
                SELECT
                    MIN(avg_price) AS mkt_min,
                    AVG(avg_price) AS mkt_avg
                FROM summary
            )
            SELECT
                COALESCE(u.facility_name, u.social_name, '—') AS unit_name,
                u.city,
                u.state,
                s.avg_price,
                s.min_price,
                s.max_price,
                CASE WHEN mkt.mkt_min > 0
                    THEN ROUND(((s.avg_price - mkt.mkt_min) / mkt.mkt_min * 100)::numeric, 1)
                    ELSE 0 END AS dispersion_pct,
                :date AS collected_date
            FROM summary s
            JOIN market_units u ON u.id = s.unit_id
            CROSS JOIN mkt
            ORDER BY {$orderSql}
            LIMIT :lim
        SQL, ['pid' => $procedureId, 'date' => $date, 'lim' => $limit]);

        // Contexto de mercado
        $prices = array_map(fn($r) => (float)$r['avg_price'], $rows);
        $mktCtx = [];
        if (!empty($prices)) {
            $mktCtx = [
                'data'         => $date,
                'total_units'  => count($rows),
                'preco_minimo' => round(min($prices), 2),
                'preco_medio'  => round(array_sum($prices) / count($prices), 2),
                'preco_maximo' => round(max($prices), 2),
                'dispersao_media_pct' => round(
                    array_sum(array_map(fn($r) => (float)$r['dispersion_pct'], $rows)) / count($rows), 1
                ),
            ];
        }

        return [
            'success'   => true,
            'procedure_id' => $procedureId,
            'mercado'   => $mktCtx,
            'count'     => count($rows),
            'data'      => $rows,
        ];
    }
}
