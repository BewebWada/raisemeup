<?php
class LineClient
{
    // pushの$quickReplyItemsにそのまま渡せる、位置情報送信ボタン1個だけのクイックリプライ
    public const LOCATION_QUICK_REPLY = [
        ['type' => 'action', 'action' => ['type' => 'location', 'label' => '位置情報を送る']],
    ];

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

    // 返信までの「入力中...」アニメーションを表示する(即レスだと機械的な印象になるため)。
    // secondsは5刻み(5〜60)である必要があるので丸める。実際のreply送信で自動的に消えるので、
    // ここでの秒数はあくまで「最大でもこれくらいで消える」という上限として渡している
    public function startLoadingAnimation(string $userId, int $seconds): void
    {
        $seconds = max(5, min(60, (int) (round($seconds / 5) * 5)));

        $ch = curl_init('https://api.line.me/v2/bot/chat/loading/start');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->accessToken,
            ],
            CURLOPT_POSTFIELDS => json_encode(['chatId' => $userId, 'loadingSeconds' => $seconds], JSON_UNESCAPED_UNICODE),
        ]);
        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            error_log("LINE loading animation failed: curl error - {$curlError}");
        } elseif ($httpCode !== 202) {
            // このエンドポイントは成功時202を返す(200ではない)
            error_log("LINE loading animation failed: HTTP {$httpCode} - {$response}");
        }
    }

    // replyTokenを持たない場面(バッチ処理からの通知等)で、こちらから能動的にメッセージを送る場合に使う。
    // $quickReplyItemsを渡すと、メッセージ下にボタンを表示できる(例: 位置情報を1タップで送れるボタン)
    public function push(string $toLineUserId, string $text, ?array $quickReplyItems = null): bool
    {
        $message = ['type' => 'text', 'text' => $text];
        if ($quickReplyItems !== null) {
            $message['quickReply'] = ['items' => $quickReplyItems];
        }
        $payload = [
            'to' => $toLineUserId,
            'messages' => [$message],
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
