<?php

namespace App\Service\Agent;

use App\Service\Agent\Tool\ToolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class DatabaseAgentService
{
    private const API_URL        = 'https://api.openai.com/v1/chat/completions';
    private const MAX_ITERATIONS = 8;

    /** @var array<string, ToolInterface> */
    private array $tools = [];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface     $logger,
        private readonly string              $apiKey,
        private readonly string              $model,
        \Traversable                         $toolsIterator,
    ) {
        foreach ($toolsIterator as $tool) {
            $this->tools[$tool->getName()] = $tool;
        }
    }

    /**
     * @param  array  $history      [{role, content}, ...]
     * @param  string $userMessage  Pergunta atual
     */
    public function chat(array $history, string $userMessage): array
    {
        // OpenAI: system prompt vai como primeira mensagem com role "system"
        $messages   = [['role' => 'system', 'content' => $this->buildSystemPrompt()]];
        $messages   = array_merge($messages, $history);
        $messages[] = ['role' => 'user', 'content' => $userMessage];
        $toolsUsed  = [];

        // OpenAI function calling usa "tools" com type "function"
        $toolDefs = array_values(
            array_map(fn(ToolInterface $t) => [
                'type'     => 'function',
                'function' => [
                    'name'        => $t->getDefinition()['name'],
                    'description' => $t->getDefinition()['description'],
                    'parameters'  => $t->getDefinition()['input_schema'],
                ],
            ], $this->tools)
        );

        for ($i = 0; $i < self::MAX_ITERATIONS; $i++) {
            $apiResult = $this->callOpenAI($messages, $toolDefs);

            if (!$apiResult['success']) {
                return [
                    'success'    => false,
                    'error'      => $apiResult['error'],
                    'messages'   => $messages,
                    'tools_used' => $toolsUsed,
                ];
            }

            $choice     = $apiResult['data']['choices'][0];
            $message    = $choice['message'];
            $finishReason = $choice['finish_reason'];

            // Adiciona resposta do assistente ao histórico
            $messages[] = $message;

            // ── Resposta final ────────────────────────────────────────────────
            if ($finishReason === 'stop') {
                return [
                    'success'    => true,
                    'message'    => $message['content'] ?? '',
                    'messages'   => $messages,
                    'tools_used' => $toolsUsed,
                ];
            }

            // ── Execução de Tools ─────────────────────────────────────────────
            if ($finishReason === 'tool_calls') {
                $toolCalls = $message['tool_calls'] ?? [];

                foreach ($toolCalls as $toolCall) {
                    $toolName  = $toolCall['function']['name'];
                    $toolInput = json_decode($toolCall['function']['arguments'], true) ?? [];
                    $toolCallId = $toolCall['id'];

                    $this->logger->info('[Agent] Tool called', [
                        'tool'  => $toolName,
                        'input' => $toolInput,
                    ]);

                    $toolsUsed[] = $toolName;

                    if (!isset($this->tools[$toolName])) {
                        $result = ['error' => "Ferramenta '{$toolName}' não encontrada."];
                    } else {
                        try {
                            $result = $this->tools[$toolName]->execute($toolInput);
                        } catch (\Throwable $e) {
                            $this->logger->error('[Agent] Tool error', [
                                'tool'  => $toolName,
                                'error' => $e->getMessage(),
                            ]);
                            $result = ['error' => $e->getMessage()];
                        }
                    }

                    // OpenAI: tool result tem role "tool"
                    $messages[] = [
                        'role'         => 'tool',
                        'tool_call_id' => $toolCallId,
                        'content'      => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ];
                }

                continue;
            }

            break;
        }

        return [
            'success'    => false,
            'error'      => 'Número máximo de iterações atingido.',
            'messages'   => $messages,
            'tools_used' => $toolsUsed,
        ];
    }

    private function callOpenAI(array $messages, array $tools): array
    {
        try {
            $body = [
                'model'    => $this->model,
                'messages' => $messages,
                'tools'    => $tools,
            ];

            // Só passa tool_choice se tiver ferramentas
            if (!empty($tools)) {
                $body['tool_choice'] = 'auto';
            }

            $response = $this->httpClient->request('POST', self::API_URL, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type'  => 'application/json',
                ],
                'json'         => $body,
                'timeout'      => 60,
                'verify_peer'  => false,  // dev only — fix curl.cainfo no Windows
                'verify_host'  => false,  // dev only — fix curl.cainfo no Windows
            ]);

            return ['success' => true, 'data' => $response->toArray()];
        } catch (\Throwable $e) {
            $this->logger->error('[Agent] OpenAI API error', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => 'Erro ao conectar à API OpenAI: ' . $e->getMessage()];
        }
    }

    private function buildSystemPrompt(): string
    {
        $today = (new \DateTime())->format('d/m/Y');

        return <<<PROMPT
        Você é o **Radar Estratégico** — analista de inteligência de mercado para laboratórios de saúde.
        Hoje é {$today}. Você tem acesso direto ao banco de dados via ferramentas.

        ## REGRAS ABSOLUTAS:
        1. SEMPRE consulte os dados reais via ferramentas antes de responder. NUNCA invente dados.
        2. Responda em português brasileiro, linguagem executiva. Sem emojis.
        3. `query_price_snapshots` SÓ deve ser usado quando a pergunta mencionar explicitamente um exame específico pelo nome. Para perguntas gerais sobre o mercado, use `query_market_daily_summary` ou `query_market_stats`.

        ## ROTEAMENTO DE FERRAMENTAS — siga rigorosamente:

        PERGUNTA GERAL (não menciona exame específico):
        → "Meu posicionamento é premium?"       → `query_market_stats` + `query_market_daily_summary`
        → "Quem domina Curitiba?"               → `list_units` + `query_market_daily_summary`
        → "Quantas unidades monitoradas?"       → `query_market_stats`
        → "Estou caro?"                         → `query_market_daily_summary`
        → "Onde posso subir preço?"             → `query_market_daily_summary`
        → "Existe dumping no mercado?"          → `query_market_daily_summary`
        → "Qual exame está em guerra de preço?" → `query_market_daily_summary`

        PERGUNTA COM EXAME ESPECÍFICO (ex: "hemograma", "ferritina", "TSH"):
        → Primeiro: `list_procedures` para obter o procedure_id pelo nome
        → Depois: `query_price_snapshots` com o procedure_id obtido

        NUNCA chame `query_price_snapshots` sem um procedure_id obtido de `list_procedures` na MESMA conversa.

        ## FERRAMENTAS DISPONÍVEIS:
        - `query_market_stats`        → contagens gerais: unidades, exames, snapshots por período
        - `query_market_daily_summary`→ evolução de preços por período (todos exames ou filtrado)
        - `query_price_snapshots`     → ranking de unidades para UM exame específico (requer procedure_id)
        - `list_procedures`           → busca procedure_id de um exame pelo nome
        - `list_units`                → lista laboratórios/unidades
        - `query_collection_logs`     → logs de execução do robô coletor (erros, falhas)

        ## FORMATO DE RESPOSTA OBRIGATÓRIO:
        Responda SEMPRE com JSON dentro de ```json ... ```:

        ```json
        {
          "diagnostico": "Texto do diagnóstico. Use markdown. 2-4 parágrafos.",
          "evidencia": {
            "Métrica 1": "valor com unidade",
            "Métrica 2": "valor",
            "Métrica 3": "valor"
          },
          "risco": "Texto sobre riscos identificados. Markdown. Null se não houver.",
          "recomendacao": "Ação concreta baseada nos dados. Markdown.",
          "periodo": "Ex: 17/02/2026 a 24/02/2026",
          "n_unidades": 87,
          "n_exames": 23,
          "query": "Ferramenta usada + parâmetros principais"
        }
        ```

        Campo "evidencia": objeto com 3-6 pares chave:valor como strings ("87 unidades", "R$ 45,00"). Nunca arrays.
        PROMPT;
    }
}