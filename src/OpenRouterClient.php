<?php
final class OpenRouterClient
{
    public function __construct(private array $config, private Storage $storage) {}

    public function complete(array $messages, array $avoidModels = []): array
    {
        if ($this->config['api_key'] === '') {
            throw new RuntimeException('OPENROUTER_API_KEY is required.');
        }
        $models = array_values(array_diff($this->config['free_models'], $avoidModels));
        foreach ($models as $model) {
            for ($attempt = 0; $attempt <= (int) $this->config['retries_per_model']; $attempt++) {
                try {
                    return ['model' => $model, 'content' => $this->request($model, $messages)];
                } catch (Throwable $e) {
                    $this->storage->appendLog('ai-failover', ['model' => $model, 'attempt' => $attempt + 1, 'error' => $e->getMessage()]);
                }
            }
        }
        throw new RuntimeException('No free OpenRouter model responded successfully.');
    }

    private function request(string $model, array $messages): string
    {
        $body = json_encode(['model' => $model, 'messages' => $messages, 'temperature' => 0.75], JSON_UNESCAPED_UNICODE);
        $ch = curl_init($this->config['endpoint']);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->config['api_key'],
                'Content-Type: application/json',
                'HTTP-Referer: ' . ($_SERVER['HTTP_HOST'] ?? 'shared-hosting'),
                'X-Title: AI SEO Site',
            ],
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => (int) $this->config['timeout'],
        ]);
        $raw = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($raw === false || $status >= 400) {
            throw new RuntimeException($error ?: 'OpenRouter HTTP ' . $status . ': ' . substr((string) $raw, 0, 300));
        }
        $json = json_decode((string) $raw, true);
        return trim((string) ($json['choices'][0]['message']['content'] ?? ''));
    }
}
