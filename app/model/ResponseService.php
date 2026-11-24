<?php

class ResponseService {
    private OpenAIClient $client;

    public function __construct(?Database $db = null) {
        $this->client = new OpenAIClient($db);
    }

    public function hasConfiguredKey(): bool {
        $info = $this->client->resolveApiKey();
        return !empty($info['found']);
    }

    public function createResponse(array $params, array $telemetry = []): array {
        $model = preg_replace('/[^a-zA-Z0-9\.\-_]/', '', (string)($params['model'] ?? 'gpt-4.1-mini')) ?: 'gpt-4.1-mini';
        $payload = [
            'model' => $model,
            'input' => $params['input'] ?? '',
            'max_output_tokens' => max(160, min(4096, (int)($params['max_output_tokens'] ?? 900)))
        ];

        if (!empty($params['instructions'])) {
            $payload['instructions'] = (string)$params['instructions'];
        }
        if (isset($params['temperature']) && $params['temperature'] !== null && !preg_match('/^(?:o[1-9]|gpt-5|gpt-6)/i', $model)) {
            $payload['temperature'] = max(0.0, min(2.0, (float)$params['temperature']));
        }
        if (!empty($params['tools']) && is_array($params['tools'])) {
            $payload['tools'] = $params['tools'];
        }
        if (!empty($params['text']) && is_array($params['text'])) {
            $payload['text'] = $params['text'];
        }
        if (!empty($params['previous_response_id'])) {
            $payload['previous_response_id'] = (string)$params['previous_response_id'];
        }

        $timeout = (int)($params['timeout_seconds'] ?? 25);
        $res = $this->client->postJson('/responses', $payload, $timeout, $telemetry);
        if (empty($res['ok'])) {
            return $res;
        }

        $decoded = $res['decoded'] ?? [];
        $res['response_id'] = (string)($decoded['id'] ?? '');
        $res['output_text'] = $this->extractOutputText($decoded);
        $res['tool_calls'] = $this->extractFunctionCalls($decoded);
        return $res;
    }

    public function createEmbedding(string $text, string $model = 'text-embedding-3-small'): ?array {
        $clean = trim($text);
        if ($clean === '') return null;
        $res = $this->client->postJson('/embeddings', [
            'model' => $model,
            'input' => mb_substr($clean, 0, 6000, 'UTF-8')
        ], 15);
        if (empty($res['ok'])) return null;
        $vec = $res['decoded']['data'][0]['embedding'] ?? null;
        return is_array($vec) ? $vec : null;
    }

    public function extractOutputText(array $decoded): string {
        if (!empty($decoded['output_text']) && is_string($decoded['output_text'])) {
            return trim($decoded['output_text']);
        }
        $chunks = [];
        foreach (($decoded['output'] ?? []) as $item) {
            if (!is_array($item) || ($item['type'] ?? '') === 'function_call') {
                continue;
            }
            foreach (($item['content'] ?? []) as $part) {
                if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                    $t = trim($part['text']);
                    if ($t !== '') $chunks[] = $t;
                }
            }
        }
        return trim(implode("\n", $chunks));
    }

    public function extractFunctionCalls(array $decoded): array {
        $calls = [];
        foreach (($decoded['output'] ?? []) as $item) {
            if (is_array($item) && ($item['type'] ?? '') === 'function_call') {
                $calls[] = [
                    'call_id' => (string)($item['call_id'] ?? ($item['id'] ?? '')),
                    'name' => (string)($item['name'] ?? ''),
                    'arguments' => is_string($item['arguments'] ?? '') ? (json_decode($item['arguments'], true) ?: []) : (is_array($item['arguments'] ?? null) ? $item['arguments'] : [])
                ];
            }
        }
        return $calls;
    }
}
