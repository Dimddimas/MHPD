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

class ProcedureAnalysisController extends AbstractController
{
    #[Route('/analise-exames', name: 'procedure_analysis_index')]
    public function index(
        Request                      $request,
        MarketDailySummaryRepository $summaryRepo,
        MarketProcedureRepository    $procedureRepo,
        MarketUnitRepository         $unitRepo,
        Connection                   $connection,
    ): Response {
        $filter      = DashboardFilterDTO::fromRequest($request->query->all());
        $procedureId = $request->query->get('procedure_id');

        // Usa data mais recente disponível
        $latestDate = $connection->fetchOne(
            "SELECT MAX(DATE(collected_at)) FROM market_price_snapshots"
        ) ?: date('Y-m-d');

        // Procedures loaded via AJAX (/analise-exames/search-procedures)
        $procedures        = [];
        $chartLabels       = $chartAvg = $chartMin = $chartMax = '[]';
        $tableRows         = [];
        $selectedProcedure = null;

        // Dados extras para o novo layout
        $diag              = [];   // bloco diagnóstico instantâneo
        $histogram         = [];   // distribuição de preços por faixa
        $competitiveRanking = [];  // ranking com distância do mínimo
        $spreadValue       = null; // spread de mercado (max - min)
        $trendWeek         = [];   // evolução 7 dias (labels + values)
        $positioningData   = [];   // dados para mapa de bolhas

        if ($procedureId) {
            // ── Dados históricos (tabela + gráfico de linha) ──────────────────
            $rows = $summaryRepo->findByProcedure($procedureId, $filter->dateFrom, $filter->dateTo);
            $chartLabels = json_encode(array_column($rows, 'collected_date'));
            $chartAvg    = json_encode(array_map('floatval', array_column($rows, 'avg_price')));
            $chartMin    = json_encode(array_map('floatval', array_column($rows, 'min_price')));
            $chartMax    = json_encode(array_map('floatval', array_column($rows, 'max_price')));
            $tableRows   = $rows;

            // Load selected procedure directly (not from full list)
            $selectedProcedure = $procedureRepo->findOneById($procedureId);

            // ── 1. Diagnóstico instantâneo (dados de hoje) ────────────────────
            $diagRow = $connection->fetchAssociative(<<<SQL
                SELECT
                    ROUND(AVG(mps.price)::numeric, 2)                               AS avg_today,
                    ROUND(MIN(mps.price)::numeric, 2)                               AS min_today,
                    ROUND(MAX(mps.price)::numeric, 2)                               AS max_today,
                    COUNT(DISTINCT mps.unit_id)                                     AS units_active,
                    CASE WHEN MIN(mps.price) > 0
                        THEN ROUND(((MAX(mps.price) - MIN(mps.price)) / MIN(mps.price) * 100)::numeric, 0)
                        ELSE 0 END                                                  AS dispersion_pct,
                    ROUND(STDDEV(mps.price)::numeric, 2)                            AS std_dev
                FROM market_price_snapshots mps
                WHERE mps.procedure_id = :pid
                  AND DATE(mps.collected_at) = :ld
            SQL, ['pid' => $procedureId, 'ld' => $latestDate]) ?: [];

            $diag = $diagRow;
            $spreadValue = isset($diag['max_today'], $diag['min_today'])
                ? round((float)$diag['max_today'] - (float)$diag['min_today'], 2)
                : null;

            // Índice de concentração: stddev baixo = concentrado, alto = disperso
            $stdDev = (float)($diag['std_dev'] ?? 0);
            $diag['concentration_label'] = match(true) {
                $stdDev <= 2  => 'Baixo',
                $stdDev <= 8  => 'Médio',
                default       => 'Alto',
            };

            // ── 2. Histograma de distribuição de preços ───────────────────────
            // Calcula as faixas dinamicamente com base no min/max do dia
            $minPrice = (float)($diag['min_today'] ?? 0);
            $maxPrice = (float)($diag['max_today'] ?? 0);
            if ($maxPrice > $minPrice) {
                $bucketSize = ceil(($maxPrice - $minPrice) / 6);  // 6 faixas
                $bucketSize = max($bucketSize, 5);
            } else {
                $bucketSize = 5;
            }

            $histRows = $connection->fetchAllAssociative(<<<SQL
                SELECT
                    FLOOR(mps.price / :bsize) * :bsize                           AS faixa_inicio,
                    FLOOR(mps.price / :bsize) * :bsize + :bsize                  AS faixa_fim,
                    COUNT(DISTINCT mps.unit_id)                                  AS qty
                FROM market_price_snapshots mps
                WHERE mps.procedure_id = :pid
                  AND DATE(mps.collected_at) = :ld
                GROUP BY faixa_inicio, faixa_fim
                ORDER BY faixa_inicio ASC
            SQL, ['pid' => $procedureId, 'bsize' => $bucketSize, 'ld' => $latestDate]);

            $histogram = $histRows;

            // ── 3. Ranking competitivo — todas unidades + distância do mínimo ─
            $minRef = (float)($diag['min_today'] ?? 0);

            $rankRows = $connection->fetchAllAssociative(<<<SQL
            SELECT
                COALESCE(u.social_name, u.facility_name, '—')   AS unit_name,
                ROUND(AVG(mps.price)::numeric, 2)                AS price,
                ROUND(AVG(mps.price)::numeric, 2)                AS avg_price,
                COUNT(*)                                          AS snapshots,
                AVG(u.latitude)                                  AS latitude,
                AVG(u.longitude)                                 AS longitude
            FROM market_price_snapshots mps
            JOIN market_units u ON u.id = mps.unit_id
            WHERE mps.procedure_id = :pid
            AND DATE(mps.collected_at) = :ld
            GROUP BY COALESCE(u.social_name, u.facility_name, '—')
            ORDER BY price ASC
        SQL, ['pid' => $procedureId, 'ld' => $latestDate]);

            // Adiciona distância percentual do mínimo e label de posição
            foreach ($rankRows as &$r) {
                $dist = $minRef > 0
                    ? round(((float)$r['price'] - $minRef) / $minRef * 100, 0)
                    : 0;
                $r['dist_pct'] = $dist;
                $r['position_label'] = match(true) {
                    $dist == 0   => 'Líder',
                    $dist <= 30  => 'Competitivo',
                    $dist <= 100 => 'Competitivo',
                    default      => 'Fora do Mercado',
                };
                $r['position_class'] = match($r['position_label']) {
                    'Líder'           => 'pos-leader',
                    'Competitivo'     => 'pos-competitive',
                    default           => 'pos-out',
                };
            }
            unset($r);
            $competitiveRanking = $rankRows;

            // ── 4. Evolução 7 dias ────────────────────────────────────────────
            $trendRows = $connection->fetchAllAssociative(<<<SQL
                SELECT
                    DATE(collected_at)               AS day,
                    ROUND(AVG(price)::numeric, 2)    AS avg_price
                FROM market_price_snapshots
                WHERE procedure_id = :pid
                  AND collected_at >= :since7
                GROUP BY DATE(collected_at)
                ORDER BY day ASC
            SQL, ['pid' => $procedureId, 'since7' => date('Y-m-d', strtotime($latestDate . ' -7 days'))]);

            $trendWeek = [
                'labels' => json_encode(array_column($trendRows, 'day')),
                'values' => json_encode(array_map('floatval', array_column($trendRows, 'avg_price'))),
            ];

            // ── 5. Dados para mapa de posicionamento (bubble chart) ──────────
            // price_ratio: quão distante do mínimo (x=dist%, y=avg, r=snapshots)
            $positioningData = json_encode(array_map(fn($r) => [
            'label' => mb_substr($r['unit_name'], 0, 20),
            'x'     => (float)$r['dist_pct'],
            'y'     => (float)$r['avg_price'],
            'r'     => min(max((int)$r['snapshots'] * 2, 4), 24),
            'lat'   => $r['latitude'] ? (float)$r['latitude'] : null,
            'lng'   => $r['longitude'] ? (float)$r['longitude'] : null,
            'color' => match($r['position_label']) {
                'Líder'       => '#059669',
                'Competitivo' => '#d97706',
                default       => '#dc2626',
            },
            'price' => (float)$r['avg_price'],
            'position' => $r['position_label'],
        ], array_slice($rankRows, 0, 30)));
        }

        return $this->render('procedure_analysis/index.html.twig', [
            'filter'             => $filter,
            'procedures'         => $procedures,
            'procedureId'        => $procedureId,
            'selectedProcedure'  => $selectedProcedure,
            'tableRows'          => $tableRows,
            'chartLabels'        => $chartLabels,
            'chartAvg'           => $chartAvg,
            'chartMin'           => $chartMin,
            'chartMax'           => $chartMax,
            // novos
            'diag'               => $diag,
            'spreadValue'        => $spreadValue,
            'histogram'          => $histogram,
            'competitiveRanking' => $competitiveRanking,
            'trendWeek'          => $trendWeek,
            'positioningData'    => $positioningData,
        ]);
    }

    /** AJAX endpoint para refresh parcial */
    #[Route('/analise-exames/data', name: 'procedure_analysis_data', methods: ['GET'])]
    public function data(
        Request                      $request,
        MarketDailySummaryRepository $summaryRepo,
    ): JsonResponse {
        $procedureId = $request->query->get('procedure_id');
        $dateFrom    = $request->query->get('date_from', (new \DateTime('-30 days'))->format('Y-m-d'));
        $dateTo      = $request->query->get('date_to',   (new \DateTime())->format('Y-m-d'));

        if (!$procedureId) {
            return $this->json(['error' => 'procedure_id obrigatório'], 400);
        }

        $rows = $summaryRepo->findByProcedure($procedureId, $dateFrom, $dateTo);

        return $this->json([
            'labels'     => array_column($rows, 'collected_date'),
            'avg_price'  => array_map('floatval', array_column($rows, 'avg_price')),
            'min_price'  => array_map('floatval', array_column($rows, 'min_price')),
            'max_price'  => array_map('floatval', array_column($rows, 'max_price')),
            'rows'       => $rows,
            'updated_at' => (new \DateTime())->format('H:i:s'),
        ]);
    }
}