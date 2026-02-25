<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Endpoints AJAX para Select2 — substitui findAllForSelect() em todos os selects.
 * Retorna máx. 50 resultados por busca, evitando carregar milhares de registros.
 */
class SearchController extends AbstractController
{
    #[Route('/search/procedures', name: 'search_procedures', methods: ['GET'])]
    public function procedures(Request $request, Connection $connection): JsonResponse
    {
        $q = trim($request->query->get('q', ''));

        $sql = "SELECT id, name, tuss_code FROM market_procedures";
        $params = [];

        if (strlen($q) >= 2) {
            $sql .= " WHERE name ILIKE :q OR tuss_code ILIKE :q2";
            $params['q']  = '%' . $q . '%';
            $params['q2'] = '%' . $q . '%';
        }

        $sql .= " ORDER BY name ASC LIMIT 50";

        $rows = $connection->fetchAllAssociative($sql, $params);

        return $this->json([
            'results' => array_map(fn($r) => [
                'id'   => $r['id'],
                'text' => $r['name'] . ($r['tuss_code'] ? ' (' . $r['tuss_code'] . ')' : ''),
            ], $rows),
        ]);
    }

    #[Route('/search/units', name: 'search_units', methods: ['GET'])]
    public function units(Request $request, Connection $connection): JsonResponse
    {
        $q = trim($request->query->get('q', ''));

        $sql = "SELECT id, COALESCE(facility_name, social_name, '—') AS name, city, state
                FROM market_units";
        $params = [];

        if (strlen($q) >= 2) {
            $sql .= " WHERE facility_name ILIKE :q OR social_name ILIKE :q2";
            $params['q']  = '%' . $q . '%';
            $params['q2'] = '%' . $q . '%';
        }

        $sql .= " ORDER BY name ASC LIMIT 50";

        $rows = $connection->fetchAllAssociative($sql, $params);

        return $this->json([
            'results' => array_map(fn($r) => [
                'id'   => $r['id'],
                'text' => $r['name'] . ($r['city'] ? ' — ' . $r['city'] . '/' . $r['state'] : ''),
            ], $rows),
        ]);
    }
}
