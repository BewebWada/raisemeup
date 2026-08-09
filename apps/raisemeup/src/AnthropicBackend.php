<?php
require_once __DIR__ . '/AiBackend.php';
require_once __DIR__ . '/AiBackendException.php';

// ClaudeClient内に12箇所あったcurlブロック(全て同一のヘッダー・エラーハンドリング構造だった)を
// そのまま1箇所に集約したもの。$bodyはAnthropic Messages APIのリクエストボディそのものを渡すだけでよく、
// レスポンスも生のデコード済み配列をそのまま返す(=ClaudeClient側の呼び出し元は無変更で動く、純粋なリファクタ)。
class AnthropicBackend implements AiBackend
{
    public function __construct(private string $apiKey)
    {
    }

    public function send(array $body, int $timeoutSeconds): array
    {
        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: 2023-06-01',
            ],
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
        ]);
        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            throw new AiBackendException("curl error - {$curlError}");
        }
        if ($httpCode !== 200) {
            throw new AiBackendException("HTTP {$httpCode} - {$response}");
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new AiBackendException('invalid JSON response - ' . substr($response, 0, 500));
        }

        return $data;
    }
}
