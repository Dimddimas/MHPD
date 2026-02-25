<?php

namespace App\Repository;

use App\DTO\DashboardFilterDTO;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ArrayParameterType;

/**
 * Acessa a materialized view market_daily_summary (DBAL puro) e as tabelas base
 * com as colunas REAIS do schema:
 *
 *  market_procedures  : id, tuss_code, name, type, created_at
 *  market_units       : id, partner_id, social_name, facility_name, city, state,
 *                       neighborhood, zipcode, address_line, number,
 *                       rating_avg, rating_count, created_at
 *  market_price_snapshots: id, unit_id, procedure_id, price, distance,
 *                          collected_at (timestamp!), source
 *  market_collection_logs: id, procedure_tuss, total_units, total_snapshots,
 *                          status, execution_time_ms, created_at
 *  market_daily_summary (view): procedure_id, collected_date, min_price,
 *                               max_price, avg_price, total_units
 */
class MarketDailySummaryRepository
{
    public function __construct(private readonly Connection $connection) {}

    // ────────────────────────────────────────────────────────────────────────────
    // Dashboard Principal
    // ────────────────────────────────────────────────────────────────────────────

    /** Série temporal agregada para o gráfico master */
    public function findPriceEvolution(DashboardFilterDTO $filter): array
    {
        $qb = $this->connection->createQueryBuilder()
            ->select(
                'mds.collected_date',
                'ROUND(AVG(mds.avg_price)::numeric, 2) AS avg_price',
                'ROUND(MIN(mds.min_price)::numeric, 2) AS min_price',
                'ROUND(MAX(mds.max_price)::numeric, 2) AS max_price',
                'SUM(mds.total_units) AS total_units'
            )
            ->from('market_daily_summary', 'mds')
            ->where('mds.collected_date BETWEEN :date_from AND :date_to')
            ->setParameter('date_from', $filter->dateFrom)
            ->setParameter('date_to',   $filter->dateTo)
            ->groupBy('mds.collected_date')
            ->orderBy('mds.collected_date', 'ASC');

        if (!empty($filter->procedureIds)) {
            $qb->andWhere('mds.procedure_id IN (:pids)')
               ->setParameter('pids', $filter->procedureIds, ArrayParameterType::STRING);
        }

        return $this->connection->fetchAllAssociative(
            $qb->getSQL(), $qb->getParameters(), $qb->getParameterTypes()
        );
    }

    /**
     * KPIs do dashboard.
     * CORREÇÃO 42702: usa INNER JOIN explícito + alias de coluna "d" nos CTEs
     * para evitar referência ambígua a collected_date.
     */
    public function findKpis(DashboardFilterDTO $filter): array
    {
        $pidsLiteral     = !empty($filter->procedureIds)
            ? "'" . implode("','", array_map('strval', $filter->procedureIds)) . "'"
            : null;
        $procedureFilter = $pidsLiteral ? "AND mds.procedure_id IN ({$pidsLiteral})" : '';

        $sql = <<<SQL
        WITH ranked_dates AS (
            SELECT   mds.collected_date,
                     ROW_NUMBER() OVER (ORDER BY mds.collected_date DESC) AS rn
            FROM     market_daily_summary mds
            WHERE    mds.collected_date <= :date_to
                     {$procedureFilter}
            GROUP BY mds.collected_date
        ),
        latest_date   AS (SELECT collected_date AS d FROM ranked_dates WHERE rn = 1),
        previous_date AS (SELECT collected_date AS d FROM ranked_dates WHERE rn = 2),
        latest AS (
            SELECT
                ROUND(AVG(mds.avg_price)::numeric, 2) AS avg_p,
                ROUND(MIN(mds.min_price)::numeric, 2) AS min_p,
                ROUND(MAX(mds.max_price)::numeric, 2) AS max_p,
                SUM(mds.total_units)                   AS units,
                COUNT(DISTINCT mds.procedure_id)       AS procedures
            FROM market_daily_summary mds
            INNER JOIN latest_date ld ON mds.collected_date = ld.d
            WHERE 1=1 {$procedureFilter}
        ),
        previous AS (
            SELECT ROUND(AVG(mds.avg_price)::numeric, 2) AS avg_p
            FROM market_daily_summary mds
            INNER JOIN previous_date pd ON mds.collected_date = pd.d
            WHERE 1=1 {$procedureFilter}
        )
        SELECT
            l.avg_p      AS current_avg,
            l.min_p      AS current_min,
            l.max_p      AS current_max,
            l.units      AS total_units,
            l.procedures AS total_procedures,
            p.avg_p      AS prev_avg,
            (SELECT d FROM latest_date) AS reference_date,
            CASE WHEN p.avg_p > 0
                 THEN ROUND(((l.avg_p - p.avg_p) / p.avg_p * 100)::numeric, 2)
                 ELSE 0 END AS pct_change
        FROM  latest l
        CROSS JOIN previous p
        SQL;

        return $this->connection->fetchAssociative($sql, ['date_to' => $filter->dateTo]) ?: [];
    }

    /**
     * Top procedures por preço médio.
     * JOIN com market_procedures usando colunas reais: name, tuss_code, type
     */
    public function findTopProceduresByAvgPrice(DashboardFilterDTO $filter, int $limit = 10): array
    {
        $qb = $this->connection->createQueryBuilder()
            ->select(
                'mp.id',
                'mp.name AS procedure_name',
                'mp.tuss_code',
                'mp.type',
                'ROUND(AVG(mds.avg_price)::numeric, 2) AS avg_price',
                'ROUND(MIN(mds.min_price)::numeric, 2) AS min_price',
                'ROUND(MAX(mds.max_price)::numeric, 2) AS max_price',
                'SUM(mds.total_units) AS total_units'
            )
            ->from('market_daily_summary', 'mds')
            ->innerJoin('mds', 'market_procedures', 'mp', 'mp.id = mds.procedure_id')
            ->where('mds.collected_date BETWEEN :date_from AND :date_to')
            ->setParameter('date_from', $filter->dateFrom)
            ->setParameter('date_to',   $filter->dateTo)
            ->groupBy('mp.id', 'mp.name', 'mp.tuss_code', 'mp.type')
            ->orderBy('avg_price', 'DESC')
            ->setMaxResults($limit);

        if (!empty($filter->procedureIds)) {
            $qb->andWhere('mds.procedure_id IN (:pids)')
               ->setParameter('pids', $filter->procedureIds, ArrayParameterType::STRING);
        }

        return $this->connection->fetchAllAssociative(
            $qb->getSQL(), $qb->getParameters(), $qb->getParameterTypes()
        );
    }

    /**
     * Distribuição por tipo de exame (coluna real: mp.type) para o donut.
     */
    public function findDistributionByCategory(DashboardFilterDTO $filter): array
    {
        $qb = $this->connection->createQueryBuilder()
            ->select(
                "COALESCE(mp.type, 'Sem tipo') AS category",
                'COUNT(DISTINCT mds.procedure_id) AS procedure_count',
                'ROUND(AVG(mds.avg_price)::numeric, 2) AS avg_price'
            )
            ->from('market_daily_summary', 'mds')
            ->innerJoin('mds', 'market_procedures', 'mp', 'mp.id = mds.procedure_id')
            ->where('mds.collected_date BETWEEN :date_from AND :date_to')
            ->setParameter('date_from', $filter->dateFrom)
            ->setParameter('date_to',   $filter->dateTo)
            ->groupBy('mp.type')
            ->orderBy('procedure_count', 'DESC')
            ->setMaxResults(10);

        if (!empty($filter->procedureIds)) {
            $qb->andWhere('mds.procedure_id IN (:pids)')
               ->setParameter('pids', $filter->procedureIds, ArrayParameterType::STRING);
        }

        return $this->connection->fetchAllAssociative(
            $qb->getSQL(), $qb->getParameters(), $qb->getParameterTypes()
        );
    }

    // ────────────────────────────────────────────────────────────────────────────
    // Página 2 — Análise por Exame
    // ────────────────────────────────────────────────────────────────────────────

    public function findByProcedure(string $procedureId, string $dateFrom, string $dateTo): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT collected_date,
                    ROUND(min_price::numeric, 2) AS min_price,
                    ROUND(max_price::numeric, 2) AS max_price,
                    ROUND(avg_price::numeric, 2) AS avg_price,
                    total_units
             FROM   market_daily_summary
             WHERE  procedure_id = :pid
               AND  collected_date BETWEEN :df AND :dt
             ORDER  BY collected_date ASC',
            ['pid' => $procedureId, 'df' => $dateFrom, 'dt' => $dateTo]
        );
    }

    // ────────────────────────────────────────────────────────────────────────────
    // Página 3 — Comparativo Unidades
    // Usa market_price_snapshots com colunas reais:
    //   collected_at (timestamp), price, distance, source
    //   market_units: social_name, facility_name, city, state, rating_avg, rating_count
    // ────────────────────────────────────────────────────────────────────────────

    public function findUnitRankingByProcedure(
        string $procedureId,
        string $dateFrom,
        string $dateTo
    ): array {
        return $this->connection->fetchAllAssociative(<<<SQL
            SELECT
                mu.id                                                   AS unit_id,
                COALESCE(mu.facility_name, mu.social_name, '—')        AS unit_name,
                mu.social_name,
                mu.city,
                mu.state,
                mu.rating_avg,
                mu.rating_count,
                ROUND(AVG(mps.price)::numeric, 2)                      AS avg_price,
                ROUND(MIN(mps.price)::numeric, 2)                      AS min_price,
                ROUND(MAX(mps.price)::numeric, 2)                      AS max_price,
                COUNT(mps.id)                                           AS snapshot_count,
                MAX(mps.collected_at)::date                            AS last_collected
            FROM market_price_snapshots mps
            INNER JOIN market_units mu ON mu.id = mps.unit_id
            WHERE mps.procedure_id = :procedure_id
              AND mps.collected_at::date BETWEEN :date_from AND :date_to
            GROUP BY mu.id, mu.facility_name, mu.social_name, mu.city,
                     mu.state, mu.rating_avg, mu.rating_count
            ORDER BY avg_price ASC
        SQL, ['procedure_id' => $procedureId, 'date_from' => $dateFrom, 'date_to' => $dateTo]);
    }

    public function findPriceEvolutionByUnits(
        string $procedureId,
        array  $unitIds,
        string $dateFrom,
        string $dateTo
    ): array {
        $unitFilter = !empty($unitIds) ? 'AND mps.unit_id IN (:unit_ids)' : '';
        $params = ['procedure_id' => $procedureId, 'date_from' => $dateFrom, 'date_to' => $dateTo];
        $types  = [];

        if (!empty($unitIds)) {
            $params['unit_ids'] = $unitIds;
            $types['unit_ids']  = ArrayParameterType::STRING;
        }

        $rows = $this->connection->fetchAllAssociative(<<<SQL
            SELECT
                mu.id                                            AS unit_id,
                COALESCE(mu.facility_name, mu.social_name, '—') AS unit_name,
                mps.collected_at::date                          AS collected_date,
                ROUND(AVG(mps.price)::numeric, 2)              AS avg_price
            FROM market_price_snapshots mps
            INNER JOIN market_units mu ON mu.id = mps.unit_id
            WHERE mps.procedure_id = :procedure_id
              AND mps.collected_at::date BETWEEN :date_from AND :date_to
              {$unitFilter}
            GROUP BY mu.id, mu.facility_name, mu.social_name, mps.collected_at::date
            ORDER BY mu.facility_name, mps.collected_at::date
        SQL, $params, $types);

        $pivot = [];
        $dates = [];
        foreach ($rows as $r) {
            $pivot[$r['unit_id']]['name']              = $r['unit_name'];
            $pivot[$r['unit_id']]['data'][$r['collected_date']] = (float) $r['avg_price'];
            $dates[$r['collected_date']]               = true;
        }
        ksort($dates);

        return ['dates' => array_keys($dates), 'series' => array_values($pivot)];
    }

    public function findUnitsOverview(DashboardFilterDTO $filter): array
    {
        $qb = $this->connection->createQueryBuilder()
            ->select(
                'mu.id',
                "COALESCE(mu.facility_name, mu.social_name, '—') AS unit_name",
                'mu.social_name',
                'mu.city',
                'mu.state',
                'mu.rating_avg',
                'mu.rating_count',
                'COUNT(DISTINCT mps.procedure_id)         AS procedures_count',
                'ROUND(AVG(mps.price)::numeric, 2)        AS avg_price',
                'ROUND(MIN(mps.price)::numeric, 2)        AS min_price',
                'ROUND(MAX(mps.price)::numeric, 2)        AS max_price',
                'COUNT(mps.id)                            AS total_snapshots',
                'MAX(mps.collected_at)::date              AS last_collected'
            )
            ->from('market_price_snapshots', 'mps')
            ->innerJoin('mps', 'market_units', 'mu', 'mu.id = mps.unit_id')
            ->where('mps.collected_at::date BETWEEN :df AND :dt')
            ->setParameter('df', $filter->dateFrom)
            ->setParameter('dt', $filter->dateTo)
            ->groupBy(
                'mu.id', 'mu.facility_name', 'mu.social_name',
                'mu.city', 'mu.state', 'mu.rating_avg', 'mu.rating_count'
            )
            ->orderBy('procedures_count', 'DESC');

        if (!empty($filter->procedureIds)) {
            $qb->andWhere('mps.procedure_id IN (:pids)')
               ->setParameter('pids', $filter->procedureIds, ArrayParameterType::STRING);
        }
        if (!empty($filter->unitIds)) {
            $qb->andWhere('mps.unit_id IN (:uids)')
               ->setParameter('uids', $filter->unitIds, ArrayParameterType::STRING);
        }

        return $this->connection->fetchAllAssociative(
            $qb->getSQL(), $qb->getParameters(), $qb->getParameterTypes()
        );
    }

    // ────────────────────────────────────────────────────────────────────────────
    // Página 4 — Data Quality
    // ────────────────────────────────────────────────────────────────────────────

    public function findCollectionStatsByDate(string $dateFrom, string $dateTo): array
    {
        return $this->connection->fetchAllAssociative(<<<SQL
            SELECT
                collected_date,
                COUNT(DISTINCT procedure_id) AS procedures_count,
                SUM(total_units)             AS total_units,
                ROUND(AVG(avg_price)::numeric, 2) AS avg_price
            FROM market_daily_summary
            WHERE collected_date BETWEEN :df AND :dt
            GROUP BY collected_date
            ORDER BY collected_date DESC
        SQL, ['df' => $dateFrom, 'dt' => $dateTo]);
    }

    // ────────────────────────────────────────────────────────────────────────────
    // Agente IA — raw query
    // ────────────────────────────────────────────────────────────────────────────

    public function rawQuery(
        ?string $dateFrom = null,
        ?string $dateTo   = null,
        array   $procedureIds = [],
        int     $limit = 200
    ): array {
        $qb = $this->connection->createQueryBuilder()
            ->select(
                'mds.procedure_id',
                'mp.name        AS procedure_name',
                'mp.tuss_code',
                'mp.type        AS procedure_type',
                'mds.collected_date',
                'ROUND(mds.min_price::numeric, 2) AS min_price',
                'ROUND(mds.max_price::numeric, 2) AS max_price',
                'ROUND(mds.avg_price::numeric, 2) AS avg_price',
                'mds.total_units'
            )
            ->from('market_daily_summary', 'mds')
            ->leftJoin('mds', 'market_procedures', 'mp', 'mp.id = mds.procedure_id')
            ->orderBy('mds.collected_date', 'DESC')
            ->setMaxResults(min($limit, 500));

        if ($dateFrom) {
            $qb->andWhere('mds.collected_date >= :df')->setParameter('df', $dateFrom);
        }
        if ($dateTo) {
            $qb->andWhere('mds.collected_date <= :dt')->setParameter('dt', $dateTo);
        }
        if (!empty($procedureIds)) {
            $qb->andWhere('mds.procedure_id IN (:pids)')
               ->setParameter('pids', $procedureIds, ArrayParameterType::STRING);
        }

        return $this->connection->fetchAllAssociative(
            $qb->getSQL(), $qb->getParameters(), $qb->getParameterTypes()
        );
    }
}
