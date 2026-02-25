<?php

namespace App\Controller;

use App\Repository\MarketProcedureRepository;
use App\Repository\MarketUnitRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class PriceSimulationController extends AbstractController
{
    #[Route('/simulacao-precos', name: 'price_simulation_index')]
    public function index(
        Request                   $request,
        MarketProcedureRepository $procedureRepo,
        MarketUnitRepository      $unitRepo,
        Connection                $connection,
    ): Response {
        $unitId      = $request->query->get('unit_id');
        $procedureId = $request->query->get('procedure_id');

        $latestDate = $connection->fetchOne(
            "SELECT MAX(DATE(collected_at)) FROM market_price_snapshots"
        ) ?: date('Y-m-d');

        // Carregados via AJAX (Select2) — sem findAllForSelect()
        $selectedUnit      = $unitRepo->findOneById($unitId);
        $selectedProcedure = $procedureRepo->findOneById($procedureId);

        $unitCurrentPrice  = null;
        $marketMin         = null;
        $marketMax         = null;
        $marketAvg         = null;
        $totalUnits        = 0;
        $collectedDate     = null;
        $scatterPoints     = '[]';

        if ($unitId && $procedureId) {

            // ── Preço atual da unidade ─────────────────────────────────────────
            $unitCurrentPrice = $connection->fetchOne(<<<SQL
                SELECT ROUND(AVG(price)::numeric, 2)
                FROM market_price_snapshots
                WHERE unit_id = :uid
                  AND procedure_id = :pid
                  AND DATE(collected_at) = :ld
            SQL, ['uid' => $unitId, 'pid' => $procedureId, 'ld' => $latestDate]) ?: null;

            // ── Sumário de mercado do dia ──────────────────────────────────────
            $mktRow = $connection->fetchAssociative(<<<SQL
                SELECT
                    ROUND(MIN(price)::numeric, 2)  AS min_price,
                    ROUND(MAX(price)::numeric, 2)  AS max_price,
                    ROUND(AVG(price)::numeric, 2)  AS avg_price,
                    COUNT(DISTINCT unit_id)         AS total_units,
                    MAX(DATE(collected_at))         AS collected_date
                FROM market_price_snapshots
                WHERE procedure_id = :pid
                  AND DATE(collected_at) = :ld
            SQL, ['pid' => $procedureId, 'ld' => $latestDate]) ?: [];

            $marketMin     = isset($mktRow['min_price'])  ? (float)$mktRow['min_price']  : null;
            $marketMax     = isset($mktRow['max_price'])  ? (float)$mktRow['max_price']  : null;
            $marketAvg     = isset($mktRow['avg_price'])  ? (float)$mktRow['avg_price']  : null;
            $totalUnits    = (int)($mktRow['total_units'] ?? 0);
            $collectedDate = $mktRow['collected_date']    ?? date('Y-m-d');

            // ── Scatter ───────────────────────────────────────────────────────
            $spRows = $connection->fetchAllAssociative(<<<SQL
                SELECT
                    COALESCE(u.facility_name, u.social_name, '—') AS unit_name,
                    u.id                                           AS unit_id,
                    ROUND(AVG(mps.price)::numeric, 2)             AS price,
                    CASE WHEN :mmin::numeric > 0
                        THEN ROUND(((AVG(mps.price) - :mmin::numeric) / :mmin::numeric * 100)::numeric, 1)
                        ELSE 0 END                                 AS dist_pct,
                    (u.id::text = :uid)                           AS is_selected
                FROM market_price_snapshots mps
                JOIN market_units u ON u.id = mps.unit_id
                WHERE mps.procedure_id = :pid
                  AND DATE(mps.collected_at) = :ld
                GROUP BY u.id, u.facility_name, u.social_name
                ORDER BY price ASC
            SQL, [
                'pid'  => $procedureId,
                'uid'  => $unitId,
                'mmin' => (string)($marketMin ?? 0),
                'ld'   => $latestDate,
            ]);

            $scatterPoints = json_encode(array_map(fn($r) => [
                'x'           => (float)$r['price'],
                'y'           => (float)$r['dist_pct'],
                'label'       => mb_substr($r['unit_name'], 0, 20),
                'is_selected' => (bool)$r['is_selected'],
            ], $spRows));
        }

        $sliderMin = $marketMin ?? 0;
        $sliderMax = $marketMax ? round($marketMax * 1.5, 2) : 100;

        return $this->render('price_simulation/index.html.twig', [
            'procedures'        => [],   // select2 AJAX
            'units'             => [],   // select2 AJAX
            'unitId'            => $unitId,
            'procedureId'       => $procedureId,
            'selectedUnit'      => $selectedUnit,
            'selectedProcedure' => $selectedProcedure,
            'unitCurrentPrice'  => $unitCurrentPrice,
            'marketMin'         => $marketMin,
            'marketMax'         => $marketMax,
            'marketAvg'         => $marketAvg,
            'totalUnits'        => $totalUnits,
            'collectedDate'     => $collectedDate,
            'scatterPoints'     => $scatterPoints,
            'sliderMin'         => $sliderMin,
            'sliderMax'         => $sliderMax,
        ]);
    }

    #[Route('/simulacao-precos/calcular', name: 'price_simulation_calc', methods: ['GET'])]
    public function calcular(
        Request    $request,
        Connection $connection,
    ): JsonResponse {
        $procedureId    = $request->query->get('procedure_id');
        $unitId         = $request->query->get('unit_id');
        $simulatedPrice = (float)$request->query->get('price', 0);

        if (!$procedureId || $simulatedPrice <= 0) {
            return $this->json(['error' => 'Parâmetros inválidos'], 400);
        }

        $latestDate = $connection->fetchOne(
            "SELECT MAX(DATE(collected_at)) FROM market_price_snapshots"
        ) ?: date('Y-m-d');

        $mkt = $connection->fetchAssociative(<<<SQL
            SELECT
                ROUND(MIN(price)::numeric, 2)  AS min_price,
                ROUND(AVG(price)::numeric, 2)  AS avg_price,
                COUNT(DISTINCT unit_id)         AS total_units
            FROM market_price_snapshots
            WHERE procedure_id = :pid
              AND DATE(collected_at) = :ld
        SQL, ['pid' => $procedureId, 'ld' => $latestDate]);

        $minPrice   = (float)($mkt['min_price']  ?? 0);
        $avgPrice   = (float)($mkt['avg_price']  ?? 0);
        $totalUnits = (int)  ($mkt['total_units'] ?? 0);

        $rankPos = (int)$connection->fetchOne(<<<SQL
            SELECT COUNT(DISTINCT unit_id)
            FROM market_price_snapshots
            WHERE procedure_id = :pid
              AND DATE(collected_at) = :ld
              AND price < :sp::numeric
        SQL, ['pid' => $procedureId, 'sp' => (string)$simulatedPrice, 'ld' => $latestDate]) + 1;

        $unitsBelow = (int)$connection->fetchOne(<<<SQL
            SELECT COUNT(DISTINCT unit_id)
            FROM market_price_snapshots
            WHERE procedure_id = :pid
              AND DATE(collected_at) = :ld
              AND price < :sp::numeric
        SQL, ['pid' => $procedureId, 'sp' => (string)$simulatedPrice, 'ld' => $latestDate]);

        $unitsAbove  = max(0, $totalUnits - $unitsBelow - 1);
        $pctAbove    = $totalUnits > 0
            ? round(($totalUnits - $unitsBelow) / $totalUnits * 100, 1)
            : 0;
        $diffToAvg   = round($simulatedPrice - $avgPrice, 2);
        $distFromMin = $minPrice > 0
            ? round(($simulatedPrice - $minPrice) / $minPrice * 100, 1)
            : 0;

        $zone = match(true) {
            $distFromMin <= 5  => ['label' => 'Verde',    'class' => 'zone-green',  'desc' => 'Zona Verde (Muito Competitivo)'],
            $distFromMin <= 15 => ['label' => 'Amarela',  'class' => 'zone-yellow', 'desc' => 'Zona Amarela (Competitivo)'],
            $distFromMin <= 30 => ['label' => 'Laranja',  'class' => 'zone-orange', 'desc' => 'Zona Laranja (Risco Médio)'],
            default            => ['label' => 'Vermelha', 'class' => 'zone-red',    'desc' => 'Zona Vermelha (Risco Alto)'],
        };

        $spRows = $connection->fetchAllAssociative(<<<SQL
            SELECT
                COALESCE(u.facility_name, u.social_name, '—') AS unit_name,
                u.id                                           AS unit_id,
                ROUND(AVG(mps.price)::numeric, 2)             AS price,
                CASE WHEN :mmin::numeric > 0
                    THEN ROUND(((AVG(mps.price) - :mmin::numeric) / :mmin::numeric * 100)::numeric, 1)
                    ELSE 0 END                                 AS dist_pct,
                (u.id::text = :uid)                           AS is_selected
            FROM market_price_snapshots mps
            JOIN market_units u ON u.id = mps.unit_id
            WHERE mps.procedure_id = :pid
              AND DATE(mps.collected_at) = :ld
            GROUP BY u.id, u.facility_name, u.social_name
            ORDER BY price ASC
        SQL, ['pid' => $procedureId, 'uid' => $unitId ?? '', 'mmin' => (string)$minPrice, 'ld' => $latestDate]);

        $scatter = array_map(fn($r) => [
            'x'           => (float)$r['price'],
            'y'           => (float)$r['dist_pct'],
            'label'       => mb_substr($r['unit_name'], 0, 20),
            'is_selected' => (bool)$r['is_selected'],
        ], $spRows);

        $simDistPct = $minPrice > 0
            ? round(($simulatedPrice - $minPrice) / $minPrice * 100, 1)
            : 0;
        foreach ($scatter as &$pt) {
            if ($pt['is_selected']) {
                $pt['x'] = $simulatedPrice;
                $pt['y'] = $simDistPct;
            }
        }
        unset($pt);

        return $this->json([
            'rank_position' => $rankPos,
            'units_below'   => $unitsBelow,
            'units_above'   => $unitsAbove,
            'total_units'   => $totalUnits,
            'pct_above'     => $pctAbove,
            'diff_to_avg'   => $diffToAvg,
            'avg_price'     => $avgPrice,
            'min_price'     => $minPrice,
            'dist_from_min' => $distFromMin,
            'zone'          => $zone,
            'scatter'       => $scatter,
            'simulated'     => $simulatedPrice,
        ]);
    }
}