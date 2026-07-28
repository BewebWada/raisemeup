<?php
// LINEログイン(OAuth)経由で、招待コードの手入力なしにLINEアカウントを連携するためのクライアント。
// Messaging APIのLineClientとは別チャネル・別APIなので分けている。
class LineLoginClient
{
    private string $channelId;
    private string $channelSecret;

    public function __construct(string $channelId, string $channelSecret)
    {
        $this->channelId = $channelId;
        $this->channelSecret = $channelSecret;
    }

    // bot_prompt=aggressiveで、同意直後に「友だち追加」専用画面を出す(通常のチェックボックスより追加率が高いため)。
    // 既に連携済みアカウントの「ログインだけ」させたい場面(マイページ等)では不要なので省略できるようにしている
    public function buildAuthorizeUrl(string $redirectUri, string $state, bool $includeBotPrompt = true): string
    {
        $params = [
            'response_type' => 'code',
            'client_id' => $this->channelId,
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'scope' => 'profile',
        ];
        if ($includeBotPrompt) {
            $params['bot_prompt'] = 'aggressive';
        }
        return 'https://access.line.me/oauth2/v2.1/authorize?' . http_build_query($params);
    }

    /**
     * 認可コードをアクセストークンに交換する。失敗時は例外を投げる。
     * @return array{access_token: string, expires_in: int, ...}
     */
    public function exchangeToken(string $code, string $redirectUri): array
    {
        $payload = http_build_query([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'client_id' => $this->channelId,
            'client_secret' => $this->channelSecret,
        ]);

        $ch = curl_init('https://api.line.me/oauth2/v2.1/token');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_POSTFIELDS => $payload,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            throw new RuntimeException("LINE token exchange failed: HTTP {$httpCode} - {$response}");
        }

        return json_decode($response, true);
    }

    /** @return array{userId: string, displayName: string, pictureUrl?: string} */
    public function getProfile(string $accessToken): array
    {
        return json_decode($this->get('https://api.line.me/v2/profile', $accessToken), true);
    }

    // このチャネルにLinked OA設定されている公式アカウントを、現在友だち追加しているかどうか
    public function isFriend(string $accessToken): bool
    {
        $data = json_decode($this->get('https://api.line.me/friendship/v1/status', $accessToken), true);
        return (bool) ($data['friendFlag'] ?? false);
    }

    private function get(string $url, string $accessToken): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            throw new RuntimeException("LINE API request failed: HTTP {$httpCode} - {$response}");
        }

        return $response;
    }
}
