<?php

namespace App\Service\Agent\Tool;

use Doctrine\DBAL\Connection;

class QueryUnitsTool implements ToolInterface
{
    public function __construct(private readonly Connection $connection) {}

    public function getName(): string { return 'list_units'; }

    public function getDefinition(): array
    {
        return [
            'name'        => $this->getName(),
            'description' => <<<DESC
            Lista as unidades/laboratórios cadastrados no banco.
            Use quando o usuário perguntar sobre laboratórios, quais unidades existem,
            quem domina o mercado, ou precisar do ID de uma unidade pelo nome.
            Retorna: id, facility_name, social_name, city, state.
            DESC,
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'search' => [
                        'type'        => 'string',
                        'description' => 'Buscar por facility_name ou social_name da unidade.',
                    ],
                    'city' => [
                        'type'        => 'string',
                        'description' => 'Filtrar por cidade.',
                    ],
                    'state' => [
                        'type'        => 'string',
                        'description' => 'Filtrar por estado (UF, ex: PR, SP).',
                    ],
                    'limit' => [
                        'type'        => 'integer',
                        'description' => 'Máximo de resultados. Padrão: 50.',
                        'default'     => 50,
                    ],
                ],
            ],
        ];
    }

    public function execute(array $input): array
    {
        $limit = min((int)($input['limit'] ?? 50), 200);

        $sql    = "SELECT id, facility_name, social_name, city, state FROM market_units WHERE 1=1";
        $params = [];

        if (!empty($input['search'])) {
            $sql .= " AND (facility_name ILIKE :s OR social_name ILIKE :s2)";
            $params['s']  = '%' . $input['search'] . '%';
            $params['s2'] = '%' . $input['search'] . '%';
        }
        if (!empty($input['city'])) {
            $sql .= " AND city ILIKE :city";
            $params['city'] = '%' . $input['city'] . '%';
        }
        if (!empty($input['state'])) {
            $sql .= " AND state = :state";
            $params['state'] = strtoupper($input['state']);
        }

        $sql .= " ORDER BY COALESCE(facility_name, social_name) ASC LIMIT " . $limit;

        $rows = $this->connection->fetchAllAssociative($sql, $params);

        return ['success' => true, 'count' => count($rows), 'data' => $rows];
    }
}