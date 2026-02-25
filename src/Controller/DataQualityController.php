<?php

namespace App\Controller;

use App\Repository\MarketCollectionLogRepository;
use App\Repository\MarketDailySummaryRepository;
use App\Repository\MarketProcedureRepository;
use App\Repository\MarketUnitRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DataQualityController extends AbstractController
{
    #[Route('/qualidade-dados', name: 'data_quality_index')]
    public function index(
        Request                       $request,
        MarketCollectionLogRepository $logRepo,
        MarketDailySummaryRepository  $summaryRepo,
        MarketProcedureRepository     $procedureRepo,
        MarketUnitRepository          $unitRepo,
    ): Response {
        $dateFrom = $request->query->get('date_from', (new \DateTime('-30 days'))->format('Y-m-d'));
        $dateTo   = $request->query->get('date_to',   (new \DateTime())->format('Y-m-d'));
        $status   = $request->query->get('status', '');

        $logs          = $logRepo->findFiltered($dateFrom, $dateTo, $status ?: null);
        $statusSummary = $logRepo->findStatusSummary();
        $coverageStats = $summaryRepo->findCollectionStatsByDate($dateFrom, $dateTo);

        $coverageLabels = json_encode(array_column($coverageStats, 'collected_date'));
        $coverageCounts = json_encode(array_map('intval', array_column($coverageStats, 'procedures_count')));
        $coverageUnits  = json_encode(array_map('intval', array_column($coverageStats, 'total_units')));

        $statusMap = [];
        foreach ($statusSummary as $s) {
            $statusMap[$s['status']] = (int)$s['total'];
        }

        return $this->render('data_quality/index.html.twig', [
            'logs'           => $logs,
            'statusMap'      => $statusMap,
            'dateFrom'       => $dateFrom,
            'dateTo'         => $dateTo,
            'statusFilter'   => $status,
            'coverageLabels' => $coverageLabels,
            'coverageCounts' => $coverageCounts,
            'coverageUnits'  => $coverageUnits,
            'procedures'     => [],
            'units'          => [],
        ]);
    }

    #[Route('/qualidade-dados/data', name: 'data_quality_data', methods: ['GET'])]
    public function data(
        Request                       $request,
        MarketCollectionLogRepository $logRepo,
    ): JsonResponse {
        $dateFrom = $request->query->get('date_from', (new \DateTime('-30 days'))->format('Y-m-d'));
        $dateTo   = $request->query->get('date_to',   (new \DateTime())->format('Y-m-d'));
        $status   = $request->query->get('status');

        return $this->json([
            'logs'       => $logRepo->findFiltered($dateFrom, $dateTo, $status ?: null),
            'summary'    => $logRepo->findStatusSummary(),
            'updated_at' => (new \DateTime())->format('H:i:s'),
        ]);
    }
}
