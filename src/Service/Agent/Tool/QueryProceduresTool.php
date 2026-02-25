<?php

namespace App\Service\Agent\Tool;

use Doctrine\DBAL\Connection;

class QueryProceduresTool implements ToolInterface
{
    public function __construct(private readonly Connection $connection) {}

    public function getName(): string { return 'list_procedures'; }

    public function getDefinition(): array
    {
        return [
            'name'        => $this->getName(),
            'description' => <<<DESC
            Lista os procedimentos/exames disponíveis no banco de dados.
            Use esta ferramenta quando o usuário perguntar quais exames existem,
            quiser buscar o ID de um exame pelo nome, ou precisar o tuss_code.
            Retorna: id, tuss_code, name, type.
            DESC,
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'search' => [
                        'type'        => 'string',
                        'description' => 'Termo de busca para filtrar por nome do exame ou tuss_code (case-insensitive).',
                    ],
                    'type' => [
                        'type'        => 'string',
                        'description' => 'Filtrar por tipo do exame.',
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

        $sql    = "SELECT id, tuss_code, name, type FROM market_procedures WHERE 1=1";
        $params = [];

        if (!empty($input['search'])) {
            $sql .= " AND (name ILIKE :search OR tuss_code ILIKE :search2)";
            $params['search']  = '%' . $input['search'] . '%';
            $params['search2'] = '%' . $input['search'] . '%';
        }
        if (!empty($input['type'])) {
            $sql .= " AND type ILIKE :type";
            $params['type'] = '%' . $input['type'] . '%';
        }

        $sql .= " ORDER BY name ASC LIMIT " . $limit;

        $rows = $this->connection->fetchAllAssociative($sql, $params);

        return ['success' => true, 'count' => count($rows), 'data' => $rows];
    }
}