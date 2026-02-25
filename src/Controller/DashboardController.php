<?php

namespace App\Controller;

use App\DTO\DashboardFilterDTO;
use App\Repository\MarketProcedureRepository;
use App\Repository\MarketUnitRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DashboardController extends AbstractController
{
    #[Route('/', name: 'dashboard_index')]
    public function index(
        Request                   $request,
        MarketProcedureRepository $procedureRepo,
        MarketUnitRepository      $unitRepo,
        Connection                $connection,
    ): Response {
        $filter = DashboardFilterDTO::fromRequest($request->query->all());

        // ── Data mais recente disponível (não assume CURRENT_DATE) ───────────
        // Evita retornar vazio quando os dados foram coletados ontem
        $latestDate = $connection->fetchOne(
            "SELECT MAX(DATE(collected_at)) FROM market_price_snapshots"
        ) ?: date('Y-m-d');

        // ══════════════════════════════════════════════════════════════
        // QUERY ÚNICA — CTE sobre market_price_snapshots
        // Usa $latestDate em vez de CURRENT_DATE
        // ══════════════════════════════════════════════════════════════
        $summaryData = $connection->fetchAllAssociative(<<<SQL
            SELECT
                mps.procedure_id,
                mp.tuss_code,
                mp.name,
                ROUND(MIN(mps.price)::numeric, 2)   AS min_price,
                ROUND(AVG(mps.price)::numeric, 2)   AS avg_price,
                ROUND(MAX(mps.price)::numeric, 2)   AS max_price,
                COUNT(DISTINCT mps.unit_id)         AS total_units,
                CASE WHEN MIN(mps.price) > 0
                    THEN ROUND(((MAX(mps.price) - MIN(mps.price)) / MIN(mps.price) * 100)::numeric, 1)
                    ELSE 0 END                       AS dispersion_pct
            FROM market_price_snapshots mps
            JOIN market_procedures mp ON mp.id = mps.procedure_id
            WHERE DATE(mps.collected_at) = :d
            GROUP BY mps.procedure_id, mp.tuss_code, mp.name
            ORDER BY avg_price DESC
            LIMIT 30
        SQL, ['d' => $latestDate]);

        // ── KPIs ─────────────────────────────────────────────────────────────
        $kpi = [
            'units_today'         => 0,
            'procedures_today'    => count($summaryData),
            'avg_market_today'    => null,
            'max_dispersion_pct'  => 0,
            'max_dispersion_name' => null,
            'max_dispersion_tuss' => null,
            'max_dispersion_min'  => 0,
            'max_dispersion_max'  => 0,
            'cheapest_price'      => null,
            'cheapest_name'       => null,
            'latest_date'         => $latestDate,
        ];

        $maxDisp       = -1.0;
        $cheapestPrice = PHP_FLOAT_MAX;
        $maxUnits      = 0;
        $sumAvg        = 0.0;

        foreach ($summaryData as $row) {
            $sumAvg += (float)$row['avg_price'];
            if ((int)$row['total_units'] > $maxUnits) {
                $maxUnits = (int)$row['total_units'];
            }
            if ((float)$row['dispersion_pct'] > $maxDisp) {
                $maxDisp                      = (float)$row['dispersion_pct'];
                $kpi['max_dispersion_pct']    = $row['dispersion_pct'];
                $kpi['max_dispersion_name']   = $row['name'];
                $kpi['max_dispersion_tuss']   = $row['tuss_code'];
                $kpi['max_dispersion_min']    = $row['min_price'];
                $kpi['max_dispersion_max']    = $row['max_price'];
            }
            if ((float)$row['min_price'] > 0 && (float)$row['min_price'] < $cheapestPrice) {
                $cheapestPrice         = (float)$row['min_price'];
                $kpi['cheapest_price'] = $row['min_price'];
                $kpi['cheapest_name']  = $row['name'];
            }
        }

        $kpi['units_today']      = $maxUnits;
        $kpi['avg_market_today'] = count($summaryData) > 0
            ? round($sumAvg / count($summaryData), 2)
            : null;

        $thermoData = $summaryData;

        // ── Heatmap top 12 por dispersão ──────────────────────────────────────
        $heatmap      = [];
        $heatmapNames = [];
        $sorted = $summaryData;
        usort($sorted, fn($a, $b) => (float)$b['dispersion_pct'] <=> (float)$a['dispersion_pct']);
        foreach (array_slice($sorted, 0, 12) as $row) {
            $pid = $row['procedure_id'];
            $heatmap[$pid] = [
                'min'  => (float)$row['min_price'],
                'avg'  => (float)$row['avg_price'],
                'max'  => (float)$row['max_price'],
                'disp' => (float)$row['dispersion_pct'],
            ];
            $heatmapNames[$pid] = $row['name'];
        }

        // ── Ranking de competitividade ────────────────────────────────────────
        $competitiveness = $connection->fetchAllAssociative(<<<SQL
            WITH daily_min AS (
                SELECT procedure_id, MIN(price) AS min_price
                FROM market_price_snapshots
                WHERE DATE(collected_at) = :d
                GROUP BY procedure_id
            )
            SELECT
                COALESCE(u.facility_name, u.social_name, '—') AS facility_name,
                COUNT(*) AS qtd_liderancas
            FROM market_price_snapshots s
            JOIN daily_min dm
                ON s.procedure_id = dm.procedure_id
               AND s.price = dm.min_price
            JOIN market_units u ON u.id = s.unit_id
            WHERE DATE(s.collected_at) = :d
            GROUP BY u.facility_name, u.social_name
            ORDER BY qtd_liderancas DESC
            LIMIT 10
        SQL, ['d' => $latestDate]);

        // ── Tendência 7 dias ──────────────────────────────────────────────────
        $trendData = $connection->fetchAllAssociative(<<<SQL
            SELECT
                DATE(collected_at)            AS collected_date,
                ROUND(AVG(price)::numeric, 2) AS avg_price
            FROM market_price_snapshots
            WHERE collected_at >= :since
            GROUP BY DATE(collected_at)
            ORDER BY collected_date ASC
        SQL, ['since' => date('Y-m-d', strtotime($latestDate . ' -7 days'))]);

        $trendLabels    = json_encode(array_column($trendData, 'collected_date'));
        $trendAvgValues = json_encode(array_map('floatval', array_column($trendData, 'avg_price')));

        return $this->render('dashboard/index.html.twig', [
            'filter'          => $filter,
            'kpi'             => $kpi,
            'thermoData'      => $thermoData,
            'trendData'       => $trendData,
            'trendLabels'     => $trendLabels,
            'trendAvgValues'  => $trendAvgValues,
            'competitiveness' => $competitiveness,
            'heatmap'         => $heatmap,
            'heatmapNames'    => $heatmapNames,
            'procedures'      => [],
            'units'           => [],
        ]);
    }
}