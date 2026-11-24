<?php

class FunctionCallingService {
    private ResponseService $responses;
    private ToolRegistryService $toolRegistry;

    public function __construct(?Database $db = null) {
        $this->responses = new ResponseService($db);
        $this->toolRegistry = new ToolRegistryService($db);
    }

    public function runConversationWithTools(array $agentConfig, array $userContext, string $instructions, array $inputMessages, array $authorizedTools, array $telemetry = [], ?callable $onEvent = null): array {
        $openAiTools = $this->toolRegistry->buildOpenAiToolSchemas($authorizedTools);
        $maxRounds = 4;
        $toolTraces = [];
        $totalTokens = 0;
        $promptTokens = 0;
        $completionTokens = 0;
        $totalCost = 0.0;

        $params = [
            'model' => (string)($agentConfig['model_name'] ?? 'gpt-4.1-mini'),
            'instructions' => $instructions,
            'input' => $inputMessages,
            'max_output_tokens' => (int)($agentConfig['max_output_tokens'] ?? 900),
            'temperature' => isset($agentConfig['temperature']) ? (float)$agentConfig['temperature'] : 0.40,
            'timeout_seconds' => (int)($agentConfig['timeout_seconds'] ?? 25)
        ];
        if (!empty($openAiTools)) {
            $params['tools'] = $openAiTools;
        }

        $finalText = '';
        for ($round = 0; $round < $maxRounds; $round++) {
            $res = $this->responses->createResponse($params, $telemetry);
            if (!empty($res['usage'])) {
                $promptTokens += (int)($res['usage']['prompt_tokens'] ?? 0);
                $completionTokens += (int)($res['usage']['completion_tokens'] ?? 0);
                $totalTokens += (int)($res['usage']['total_tokens'] ?? 0);
                $totalCost += (float)($res['usage']['estimated_cost_usd'] ?? 0.0);
            }

            if (empty($res['ok'])) {
                return [
                    'ok' => false,
                    'missing_key' => !empty($res['missing_key']),
                    'error' => (string)($res['decoded']['error']['message'] ?? 'OpenAI request failed.'),
                    'tool_traces' => $toolTraces,
                    'usage' => [
                        'prompt_tokens' => $promptTokens,
                        'completion_tokens' => $completionTokens,
                        'total_tokens' => $totalTokens,
                        'estimated_cost_usd' => round($totalCost, 6)
                    ]
                ];
            }

            $calls = $res['tool_calls'] ?? [];
            if (empty($calls)) {
                $finalText = (string)($res['output_text'] ?? '');
                break;
            }

            $toolOutputs = [];
            foreach ($calls as $call) {
                $toolName = (string)$call['name'];
                $callId = (string)$call['call_id'];
                $args = is_array($call['arguments']) ? $call['arguments'] : [];

                if ($onEvent) {
                    $onEvent('working', "Executing tool {$toolName}", 'typing');
                }
                $exec = $this->toolRegistry->executeTool($toolName, $args, $userContext, $agentConfig);
                $toolTraces[] = [
                    'tool_code' => $toolName,
                    'ok' => !empty($exec['ok']),
                    'duration_ms' => (int)($exec['duration_ms'] ?? 0),
                    'data' => $exec['ok'] ? $exec['data'] : ['error' => $exec['error'] ?? 'Denied']
                ];
                $toolOutputs[] = [
                    'type' => 'function_call_output',
                    'call_id' => $callId,
                    'output' => json_encode($exec['ok'] ? $exec['data'] : ['error' => $exec['error'] ?? 'Denied'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                ];
            }

            $params['previous_response_id'] = (string)$res['response_id'];
            $params['input'] = $toolOutputs;
            unset($params['instructions']);
        }

        return [
            'ok' => $finalText !== '',
            'text' => $finalText,
            'tool_traces' => $toolTraces,
            'usage' => [
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'total_tokens' => $totalTokens,
                'estimated_cost_usd' => round($totalCost, 6)
            ]
        ];
    }
}
