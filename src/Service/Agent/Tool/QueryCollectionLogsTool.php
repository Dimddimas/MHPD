<?php

namespace App\Service\Agent\Tool;

use Doctrine\DBAL\Connection;

class QueryCollectionLogsTool implements ToolInterface
{
    public function __construct(private readonly Connection $connection) {}

    public function getName(): string { return 'query_collection_logs'; }

    public function getDefinition(): array
    {
        return [
            'name'        => $this->getName(),
            'description' => <<<DESC
            Consulta os logs de execução do coletor de dados (market_collection_logs).
            Use APENAS quando o usuário perguntar sobre:
            - Falhas ou erros nas coletas
            - Histórico de execuções do robô coletor
            - Tempo de execução das coletas
            NÃO use esta ferramenta para contar unidades monitoradas — use query_price_snapshots para isso.
            Retorna: id, procedure_tuss, total_units, total_snapshots, status, execution_time_ms, created_at.
            DESC,
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'status' => [
                        'type'        => 'string',
                        'enum'        => ['success', 'partial', 'failed'],
                        'description' => 'Filtrar por status da execução.',
                    ],
                    'date_from' => ['type' => 'string', 'description' => 'Data inicial YYYY-MM-DD (filtra por created_at).'],
                    'date_to'   => ['type' => 'string', 'description' => 'Data final YYYY-MM-DD (filtra por created_at).'],
                    'limit'     => ['type' => 'integer', 'default' => 20, 'description' => 'Máximo de registros. Padrão: 20.'],
                ],
            ],
        ];
    }

    public function execute(array $input): array
    {
        $limit = min((int)($input['limit'] ?? 20), 100);

        $sql    = "SELECT id, procedure_tuss, total_units, total_snapshots, status, execution_time_ms, created_at FROM market_collection_logs WHERE 1=1";
        $params = [];

        if (!empty($input['status'])) {
            $sql .= " AND status = :status";
            $params['status'] = $input['status'];
        }
        if (!empty($input['date_from'])) {
            $sql .= " AND DATE(created_at) >= :df";
            $params['df'] = $input['date_from'];
        }
        if (!empty($input['date_to'])) {
            $sql .= " AND DATE(created_at) <= :dt";
            $params['dt'] = $input['date_to'];
        }

        $sql .= " ORDER BY created_at DESC LIMIT " . $limit;

        $rows = $this->connection->fetchAllAssociative($sql, $params);

        return ['success' => true, 'count' => count($rows), 'data' => $rows];
    }
}