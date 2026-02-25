<?php

namespace App\Controller;

use App\DTO\DashboardFilterDTO;
use App\Repository\MarketDailySummaryRepository;
use App\Repository\MarketProcedureRepository;
use App\Repository\MarketUnitRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class UnitComparisonController extends AbstractController
{
    #[Route('/comparativo-unidades', name: 'unit_comparison_index')]
    public function index(
        Request                      $request,
        MarketDailySummaryRepository $summaryRepo,
        MarketProcedureRepository    $procedureRepo,
        MarketUnitRepository         $unitRepo,
        Connection                   $connection,
    ): Response {
        $filter      = DashboardFilterDTO::fromRequest($request->query->all());
        $unitId      = $request->query->get('unit_id');
        $procedureId = $request->query->get('procedure_id');

        $latestDate = $connection->fetchOne(
            "SELECT MAX(DATE(collected_at)) FROM market_price_snapshots"
        ) ?: date('Y-m-d');

        // Loaded via AJAX
        $procedures    = [];
        $units         = [];
        $unitsOverview = $summaryRepo->findUnitsOverview($filter);
        $totalUnits    = count($unitsOverview);

        // ── Modo geral com procedure ──────────────────────────────────────────
        $unitRanking       = [];
        $multilineData     = ['dates' => [], 'series' => []];
        $selectedProcedure = null;

        if ($procedureId) {
            $unitRanking = $summaryRepo->findUnitRankingByProcedure(
                $procedureId, $filter->dateFrom, $filter->dateTo
            );
            $topUnitIds    = array_slice(array_column($unitRanking, 'unit_id'), 0, 8);
            $multilineData = $summaryRepo->findPriceEvolutionByUnits(
                $procedureId, $topUnitIds, $filter->dateFrom, $filter->dateTo
            );
            $selectedProcedure = $procedureRepo->findOneById($procedureId);
        }

        // ── Modo diagnóstico (unit_id selecionado) ────────────────────────────
        $selectedUnit       = null;
        $warTable           = [];
        $competIdx          = 0;
        $leaderCount        = 0;
        $totalProcs         = 0;
        $rankingPosition    = 1;
        $avgDeltaPct        = null;
        $trendUnit          = ['labels' => '[]', 'unit_values' => '[]', 'market_values' => '[]'];
        $scatterData        = '[]';
        $riskBands          = ['5' => 0, '10' => 0, '20' => 0];

        if ($unitId) {
            // Nome da unidade
            $selectedUnit = $unitRepo->findOneById($unitId);

            // ── Tabela de Guerra ──────────────────────────────────────────────
            $warRows = $connection->fetchAllAssociative(<<<SQL
                WITH unit_prices AS (
                    SELECT
                        mps.procedure_id,
                        ROUND(AVG(mps.price)::numeric, 2) AS unit_avg
                    FROM market_price_snapshots mps
                    WHERE mps.unit_id = :uid
                      AND DATE(mps.collected_at) = :ld
                    GROUP BY mps.procedure_id
                ),
                market_prices AS (
                    SELECT
                        mps.procedure_id,
                        ROUND(MIN(mps.price)::numeric, 2) AS mkt_min,
                        ROUND(AVG(mps.price)::numeric, 2) AS mkt_avg,
                        ROUND(MAX(mps.price)::numeric, 2) AS mkt_max
                    FROM market_price_snapshots mps
                    WHERE DATE(mps.collected_at) = :ld
                    GROUP BY mps.procedure_id
                )
                SELECT
                    mp.name                          AS exam_name,
                    up.unit_avg                      AS unit_price,
                    mkt.mkt_min,
                    mkt.mkt_avg,
                    mkt.mkt_max,
                    CASE WHEN mkt.mkt_min > 0
                        THEN ROUND(((up.unit_avg - mkt.mkt_min) / mkt.mkt_min * 100)::numeric, 0)
                        ELSE 0 END                   AS diff_pct
                FROM unit_prices up
                JOIN market_prices mkt ON mkt.procedure_id = up.procedure_id
                JOIN market_procedures mp ON mp.id = up.procedure_id
                ORDER BY diff_pct ASC
            SQL, ['uid' => $unitId, 'ld' => $latestDate]);

            $warTable   = $warRows;
            $totalProcs = count($warRows);

            $leaderCount = count(array_filter($warRows, fn($r) => (int)$r['diff_pct'] === 0));
            $competIdx   = $totalProcs > 0 ? round($leaderCount / $totalProcs * 100) : 0;

            $deltas      = array_map(fn($r) => (float)$r['diff_pct'], $warRows);
            $avgDeltaPct = count($deltas) > 0 ? round(array_sum($deltas) / count($deltas), 1) : null;

            // Risco
            foreach ($warRows as $r) {
                $d = abs((float)$r['diff_pct']);
                if ($d > 0 && $d <= 5)  $riskBands['5']++;
                if ($d > 0 && $d <= 10) $riskBands['10']++;
                if ($d > 0 && $d <= 20) $riskBands['20']++;
            }

            // ── Ranking geral da unidade ──────────────────────────────────────
            $unitAvg = $connection->fetchOne(
                'SELECT ROUND(AVG(price)::numeric,2) FROM market_price_snapshots WHERE unit_id = :uid AND DATE(collected_at) = :ld',
                ['uid' => $unitId, 'ld' => $latestDate]
            ) ?: 0;

            $betterCount = 0;
            foreach ($unitsOverview as $ov) {
                if ((float)$ov['avg_price'] < (float)$unitAvg) $betterCount++;
            }
            $rankingPosition = $betterCount + 1;

            // ── Evolução 7 dias ───────────────────────────────────────────────
            $trendRows = $connection->fetchAllAssociative(<<<SQL
                SELECT
                    DATE(mps.collected_at)                                           AS day,
                    ROUND(AVG(CASE WHEN mps.unit_id = :uid THEN mps.price END)::numeric, 2) AS unit_avg,
                    ROUND(AVG(mps.price)::numeric, 2)                                AS market_avg
                FROM market_price_snapshots mps
                WHERE mps.collected_at >= :since7
                GROUP BY DATE(mps.collected_at)
                ORDER BY day ASC
            SQL, ['uid' => $unitId, 'since7' => date('Y-m-d', strtotime($latestDate . ' -7 days'))]);

            $trendUnit = [
                'labels'        => json_encode(array_column($trendRows, 'day')),
                'unit_values'   => json_encode(array_map('floatval', array_column($trendRows, 'unit_avg'))),
                'market_values' => json_encode(array_map('floatval', array_column($trendRows, 'market_avg'))),
            ];

            // ── Scatter todas as unidades ─────────────────────────────────────
            $scatterRaw = $connection->fetchAllAssociative(<<<SQL
                WITH daily_min AS (
                    SELECT procedure_id, MIN(price) AS min_price
                    FROM market_price_snapshots
                    WHERE DATE(collected_at) = :ld
                    GROUP BY procedure_id
                )
                SELECT
                    mu.id,
                    COALESCE(mu.facility_name, mu.social_name, '—') AS unit_name,
                    ROUND(AVG(mps.price)::numeric, 2)                AS avg_price,
                    ROUND(AVG(
                        CASE WHEN dm.min_price > 0
                        THEN (mps.price - dm.min_price) / dm.min_price * 100
                        ELSE 0 END
                    )::numeric, 1)                                   AS dist_pct,
                    COUNT(DISTINCT mps.procedure_id)                 AS proc_count,
                    (mu.id::text = :uid)                             AS is_selected
                FROM market_price_snapshots mps
                JOIN market_units mu ON mu.id = mps.unit_id
                JOIN daily_min dm    ON dm.procedure_id = mps.procedure_id
                WHERE DATE(mps.collected_at) = :ld
                GROUP BY mu.id, mu.facility_name, mu.social_name
                ORDER BY avg_price ASC
            SQL, ['uid' => $unitId, 'ld' => $latestDate]);

            $scatterData = json_encode(array_map(fn($r) => [
                'x'           => (float)$r['avg_price'],
                'y'           => (float)$r['dist_pct'],
                'r'           => $r['is_selected'] ? 14 : 5,
                'label'       => mb_substr($r['unit_name'], 0, 20),
                'is_selected' => (bool)$r['is_selected'],
            ], $scatterRaw));
        }

        return $this->render('unit_comparison/index.html.twig', [
            'filter'            => $filter,
            'procedures'        => $procedures,
            'units'             => $units,
            'unitId'            => $unitId,
            'procedureId'       => $procedureId,
            'selectedUnit'      => $selectedUnit,
            'selectedProcedure' => $selectedProcedure,
            'unitsOverview'     => $unitsOverview,
            'unitRanking'       => $unitRanking,
            'multilineLabels'   => json_encode($multilineData['dates']),
            'multilineSeries'   => json_encode($multilineData['series']),
            // diagnóstico
            'warTable'          => $warTable,
            'competIdx'         => $competIdx,
            'leaderCount'       => $leaderCount,
            'totalProcs'        => $totalProcs,
            'rankingPosition'   => $rankingPosition,
            'totalUnits'        => $totalUnits,
            'avgDeltaPct'       => $avgDeltaPct,
            'trendUnit'         => $trendUnit,
            'scatterData'       => $scatterData,
            'riskBands'         => $riskBands,
        ]);
    }

    #[Route('/comparativo-unidades/data', name: 'unit_comparison_data', methods: ['GET'])]
    public function data(
        Request                      $request,
        MarketDailySummaryRepository $summaryRepo,
    ): JsonResponse {
        $filter      = DashboardFilterDTO::fromRequest($request->query->all());



        $procedureId = $request->query->get('procedure_id');

        $unitsOverview = $summaryRepo->findUnitsOverview($filter);
        $result = ['overview' => $unitsOverview, 'updated_at' => (new \DateTime())->format('H:i:s')];

        if ($procedureId) {
            $ranking = $summaryRepo->findUnitRankingByProcedure($procedureId, $filter->dateFrom, $filter->dateTo);
            $topIds  = array_slice(array_column($ranking, 'unit_id'), 0, 8);
            $ml      = $summaryRepo->findPriceEvolutionByUnits($procedureId, $topIds, $filter->dateFrom, $filter->dateTo);
            $result['ranking']   = $ranking;
            $result['multiline'] = $ml;
        }

        return $this->json($result);
    }
}