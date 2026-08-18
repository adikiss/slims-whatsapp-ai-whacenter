<?php

namespace WaNotif;

class AiClient
{
    private string $apiKey;
    private string $model;
    private string $baseUrl;

    public function __construct(string $apiKey, string $model = 'google/gemini-2.0-flash-001', string $baseUrl = 'https://openrouter.ai/api/v1')
    {
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public static function fromConfig(): ?self
    {
        $config = WaConfig::load();
        if (empty($config['ai_api_key'])) return null;
        return new self(
            $config['ai_api_key'],
            $config['ai_model'] ?? 'google/gemini-2.0-flash-001',
            $config['ai_base_url'] ?? 'https://openrouter.ai/api/v1'
        );
    }

    public function chat(string $systemPrompt, string $userMessage, array $context = []): array
    {
        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
        ];

        if (!empty($context)) {
            $contextText = "Berikut data dari database perpustakaan yang relevan:\n";
            foreach ($context as $key => $value) {
                if (is_array($value)) {
                    $contextText .= "- {$key}: " . json_encode($value, JSON_UNESCAPED_UNICODE) . "\n";
                } else {
                    $contextText .= "- {$key}: {$value}\n";
                }
            }
            $messages[] = ['role' => 'system', 'content' => $contextText];
        }

        $messages[] = ['role' => 'user', 'content' => $userMessage];

        $payload = json_encode([
            'model' => $this->model,
            'messages' => $messages,
            'max_tokens' => 1024,
            'temperature' => 0.7,
        ]);

        $ch = curl_init("{$this->baseUrl}/chat/completions");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['ok' => false, 'message' => "cURL error: {$error}"];
        }

        $body = json_decode($response, true);
        if ($httpCode !== 200) {
            $errMsg = $body['error']['message'] ?? $response;
            return ['ok' => false, 'message' => "API error ({$httpCode}): {$errMsg}"];
        }

        $reply = $body['choices'][0]['message']['content'] ?? '';
        if ($reply === '') {
            return ['ok' => false, 'message' => 'Empty response from AI'];
        }

        return ['ok' => true, 'message' => $reply];
    }
}
