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

    // 既にCustomerに保存済みの支払い方法を使って、Checkoutを経由せず直接Subscriptionを作成する。
    // 家族が既に契約済み(=カード登録済み)の状態で2人目以降の利用者を追加する際に使い、
    // カード情報の再入力を発生させないための経路(1人目の初回契約はCheckout Session経由のまま)
    // $trialDaysに0以下を渡すとtrial_period_daysを付けない(=即時課金)。無料期間が既に終わっている
    // 状態でカードだけ後から登録された場合(mypage.php参照)に使う
    public function createSubscription(string $customerId, string $priceId, int $trialDays, array $metadata = []): array
    {
        $params = [
            'customer' => $customerId,
            'items' => [['price' => $priceId]],
        ];
        if ($trialDays > 0) {
            $params['trial_period_days'] = $trialDays;
        }
        if (!empty($metadata)) {
            $params['metadata'] = $metadata;
        }
        return $this->request('POST', '/subscriptions', $params);
    }

    // 即時キャンセル(日割り等は行わない)。放置された未連携契約が意図せず課金される前に止める用途で使う
    public function cancelSubscription(string $subscriptionId): array
    {
        return $this->request('DELETE', '/subscriptions/' . $subscriptionId);
    }

    // 期間終了時に解約を予約する(即時停止ではなく、支払い済み期間の終わりまで利用を継続させる)。
    // 実際の利用停止・DB反映はStripeが期間終了時に送るcustomer.subscription.deletedのWebhookで行う
    public function cancelSubscriptionAtPeriodEnd(string $subscriptionId): array
    {
        return $this->request('POST', '/subscriptions/' . $subscriptionId, ['cancel_at_period_end' => true]);
    }

    // Stripeがホストするお支払い管理画面(カード変更・請求書履歴・解約)のセッションを発行する。
    // 事前にStripeダッシュボード側で「Customer portal」の設定(何を許可するか)が必要
    // $flowType: 'payment_method_update' を渡すと、ポータルのトップ画面を経由せず
    // カード追加画面へ直接ディープリンクする(未指定ならポータルのトップ画面)
    public function createBillingPortalSession(string $customerId, string $returnUrl, ?string $flowType = null): array
    {
        $params = [
            'customer' => $customerId,
            'return_url' => $returnUrl,
        ];
        if ($flowType !== null) {
            $params['flow_data'] = ['type' => $flowType];
        }
        return $this->request('POST', '/billing_portal/sessions', $params);
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
