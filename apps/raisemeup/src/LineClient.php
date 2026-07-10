<?php
class LineClient
{
    private string $channelSecret;
    private string $accessToken;

    public function __construct(string $channelSecret, string $accessToken)
    {
        $this->channelSecret = $channelSecret;
        $this->accessToken = $accessToken;
    }

    public function verifySignature(string $body, string $signature): bool
    {
        $hash = base64_encode(hash_hmac('sha256', $body, $this->channelSecret, true));
        return hash_equals($hash, $signature);
    }

    public function reply(string $replyToken, string $text): void
    {
        $payload = [
            'replyToken' => $replyToken,
            'messages' => [['type' => 'text', 'text' => $text]],
        ];

        $ch = curl_init('https://api.line.me/v2/bot/message/reply');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->accessToken,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            error_log("LINE reply failed: curl error - {$curlError}");
        } elseif ($httpCode !== 200) {
            error_log("LINE reply failed: HTTP {$httpCode} - {$response}");
        }
    }

    // replyTokenを持たない場面(バッチ処理からの通知等)で、こちらから能動的にメッセージを送る場合に使う
    public function push(string $toLineUserId, string $text): bool
    {
        $payload = [
            'to' => $toLineUserId,
            'messages' => [['type' => 'text', 'text' => $text]],
        ];

        $ch = curl_init('https://api.line.me/v2/bot/message/push');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->accessToken,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            error_log("LINE push failed: curl error - {$curlError}");
            return false;
        }
        if ($httpCode !== 200) {
            error_log("LINE push failed: HTTP {$httpCode} - {$response}");
            return false;
        }
        return true;
    }
}
