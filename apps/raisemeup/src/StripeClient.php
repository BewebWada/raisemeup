<?php
// LineClient/ClaudeClientと同じ方針(SDK非導入、生cURL)で書いた最小限のStripe APIラッパー。
class StripeClient
{
    private const API_BASE = 'https://api.stripe.com/v1';

    private string $secretKey;
    private string $webhookSecret;

    public function __construct(string $secretKey, string $webhookSecret = '')
    {
        $this->secretKey = $secretKey;
        $this->webhookSecret = $webhookSecret;
    }

    public function createCustomer(string $email, string $name, array $metadata = []): array
    {
        $params = ['name' => $name];
        if ($email !== '') {
            $params['email'] = $email;
        }
        if (!empty($metadata)) {
            $params['metadata'] = $metadata;
        }
        return $this->request('POST', '/customers', $params);
    }

    public function createProduct(string $name, string $description = ''): array
    {
        $params = ['name' => $name];
        if ($description !== '') {
            $params['description'] = $description;
        }
        return $this->request('POST', '/products', $params);
    }

    // JPYはStripeのゼロデシマル通貨なので、円の金額をそのままunit_amountに渡してよい(*100不要)
    public function createPrice(string $productId, int $unitAmountYen, string $interval = 'month'): array
    {
        return $this->request('POST', '/prices', [
            'product' => $productId,
            'unit_amount' => $unitAmountYen,
            'currency' => 'jpy',
            'recurring' => ['interval' => $interval],
        ]);
    }

    public function createCheckoutSession(array $params): array
    {
        return $this->request('POST', '/checkout/sessions', $params + ['mode' => 'subscription']);
    }

    // Stripe-Signatureヘッダを検証し、ペイロードをデコードして返す。検証失敗時は例外を投げる。
    public function constructEvent(string $payload, string $sigHeader): array
    {
        if ($this->webhookSecret === '') {
            throw new RuntimeException('STRIPE_WEBHOOK_SECRET is not configured');
        }

        $parts = [];
        foreach (explode(',', $sigHeader) as $pair) {
            [$k, $v] = array_pad(explode('=', $pair, 2), 2, null);
            if ($k !== null) {
                $parts[$k][] = $v;
            }
        }

        $timestamp = $parts['t'][0] ?? null;
        $signatures = $parts['v1'] ?? [];
        if ($timestamp === null || empty($signatures)) {
            throw new RuntimeException('Invalid Stripe-Signature header');
        }
        if (abs(time() - (int) $timestamp) > 300) {
            throw new RuntimeException('Stripe webhook timestamp outside tolerance');
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $this->webhookSecret);
        $verified = false;
        foreach ($signatures as $sig) {
            if ($sig !== null && hash_equals($expected, $sig)) {
                $verified = true;
                break;
            }
        }
        if (!$verified) {
            throw new RuntimeException('Stripe webhook signature verification failed');
        }

        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid Stripe webhook payload');
        }
        return $decoded;
    }

    private function request(string $method, string $path, array $params = []): array
    {
        $body = http_build_query($this->flattenParams($params));

        $ch = curl_init(self::API_BASE . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->secretKey,
                'Content-Type: application/x-www-form-urlencoded',
            ],
        ]);
        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException("Stripe API request failed: curl error - {$curlError}");
        }

        $decoded = json_decode($response, true);
        if ($httpCode >= 400) {
            $message = $decoded['error']['message'] ?? "HTTP {$httpCode}";
            throw new RuntimeException("Stripe API error ({$path}): {$message}");
        }

        return is_array($decoded) ? $decoded : [];
    }

    // ネストした連想配列/添字配列をStripeのbracket記法(line_items[0][price]=...)にflattenする
    private function flattenParams(array $params, string $prefix = ''): array
    {
        $result = [];
        foreach ($params as $key => $value) {
            $fullKey = $prefix === '' ? (string) $key : "{$prefix}[{$key}]";
            if (is_array($value)) {
                $result += $this->flattenParams($value, $fullKey);
            } elseif ($value !== null) {
                $result[$fullKey] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            }
        }
        return $result;
    }
}
