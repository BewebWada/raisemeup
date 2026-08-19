<?php
// 「利用者ごとの解約」「申込者(ご家族)によるすべての解約」の両方から使う共通ロジック。
// 方針: 支払い済み期間の終わりまで利用を継続させる(即時停止しない)。Stripe契約がある場合は
// cancel_at_period_endを予約するだけにとどめ、実際の終了処理(利用者の終了化・家族との紐づけ解除・
// お別れメッセージ)は期間終了時にStripeから届くcustomer.subscription.deleted Webhook
// (stripe_webhook.php)を受けてfinalizeTermination()で行う。
// トライアル中でカード未登録(Stripe契約がまだ存在しない)場合だけは課金の実体が無いため、
// requestUserCancellation()の中でその場までfinalizeTermination()まで進める。
class CancellationService
{
    private PDO $pdo;
    private StripeClient $stripe;
    private SubscriptionRepository $subscriptionRepo;
    private UserRepository $userRepo;
    private FamilyAccountRepository $familyRepo;
    private LineClient $userLineClient;

    public function __construct(
        PDO $pdo,
        StripeClient $stripe,
        SubscriptionRepository $subscriptionRepo,
        UserRepository $userRepo,
        FamilyAccountRepository $familyRepo,
        LineClient $userLineClient
    ) {
        $this->pdo = $pdo;
        $this->stripe = $stripe;
        $this->subscriptionRepo = $subscriptionRepo;
        $this->userRepo = $userRepo;
        $this->familyRepo = $familyRepo;
        $this->userLineClient = $userLineClient;
    }

    public function requestUserCancellation(int $userId, int $familyAccountId): void
    {
        $sub = $this->subscriptionRepo->findActiveForUser($userId);
        if ($sub === null) {
            return;
        }

        if (!empty($sub['payment_customer_ref'])) {
            $stripeSub = $this->stripe->cancelSubscriptionAtPeriodEnd((string) $sub['payment_customer_ref']);
            if (isset($stripeSub['cancel_at'])) {
                $this->subscriptionRepo->scheduleCancellation((int) $sub['id'], self::unixToJst((int) $stripeSub['cancel_at']));
            }
        } else {
            // カード未登録(Stripe契約が存在しない)トライアルは、課金の実体が無いためその場で終了させる
            $this->subscriptionRepo->markCancelled((int) $sub['id']);
            $this->finalizeTermination($userId, $familyAccountId);
        }
    }

    // Stripe契約が実際に終了した時点(Webhook)、またはStripe契約が無い即時解約の時点で呼ぶ終端処理。
    // Webhook再送等での二重実行に備え、既に終了済みなら何もしない(冪等)
    public function finalizeTermination(int $userId, int $familyAccountId): void
    {
        $user = $this->userRepo->find($userId);
        if ($user === null || $user['status'] === 'terminated') {
            return;
        }

        $this->userRepo->terminate($userId);
        $this->familyRepo->deactivateLink($userId, $familyAccountId);

        if ($user['line_user_id'] !== null) {
            $name = (string) ($user['display_name'] ?: '');
            $greeting = $name !== '' ? "{$name}さん、" : '';
            $this->userLineClient->push(
                (string) $user['line_user_id'],
                "{$greeting}今までお話しできて嬉しかったです。TAYORIとのご利用は本日までとなりました。またお会いできる日を楽しみにしています。お元気で。"
            );
        }
    }

    private static function unixToJst(int $timestamp): string
    {
        $dt = new DateTime('@' . $timestamp);
        $dt->setTimezone(new DateTimeZone('Asia/Tokyo'));
        return $dt->format('Y-m-d H:i:s');
    }
}
