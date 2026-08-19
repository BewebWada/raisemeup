<?php
class SubscriptionRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function createTrial(int $userId, int $familyAccountId, int $planId, int $trialDays): int
    {
        $this->pdo->prepare(
            "INSERT INTO subscriptions (user_id, family_account_id, plan_id, status, trial_ends_at)
             VALUES (?, ?, ?, 'trial', DATE_ADD(NOW(), INTERVAL ? DAY))"
        )->execute([$userId, $familyAccountId, $planId, $trialDays]);

        return (int) $this->pdo->lastInsertId();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM subscriptions WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    // プラン別機能出し分け(例: 家族へのリスク通知はfamily_watch以上限定)用。
    // 現状は利用者1人につき契約は1件のみ作られる想定なので、最新の1件のプランをそのまま「現在のプラン」として扱う
    public function getCurrentPlanCodeForUser(int $userId): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.code FROM subscriptions s JOIN plans p ON p.id = s.plan_id
             WHERE s.user_id = ? ORDER BY s.id DESC LIMIT 1'
        );
        $stmt->execute([$userId]);
        $code = $stmt->fetchColumn();
        return $code !== false ? (string) $code : null;
    }

    // payment_customer_refにはStripeのSubscription ID(sub_...)を保持する
    public function findByStripeSubscriptionId(string $stripeSubscriptionId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM subscriptions WHERE payment_provider = 'stripe' AND payment_customer_ref = ?"
        );
        $stmt->execute([$stripeSubscriptionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    // Stripe Checkout完了時(checkout.session.completed)に、ローカルのtrial契約とStripe側の契約を紐づける
    public function attachStripeSubscription(int $id, string $stripeSubscriptionId): void
    {
        $this->pdo->prepare(
            "UPDATE subscriptions SET payment_provider = 'stripe', payment_customer_ref = ? WHERE id = ?"
        )->execute([$stripeSubscriptionId, $id]);
    }

    public function markActive(int $id, ?string $currentPeriodEnd = null): void
    {
        $this->pdo->prepare(
            "UPDATE subscriptions SET status = 'active', current_period_end = COALESCE(?, current_period_end) WHERE id = ?"
        )->execute([$currentPeriodEnd, $id]);
    }

    public function markPastDue(int $id): void
    {
        $this->pdo->prepare("UPDATE subscriptions SET status = 'past_due' WHERE id = ?")->execute([$id]);
    }

    public function markCancelled(int $id): void
    {
        $this->pdo->prepare("UPDATE subscriptions SET status = 'cancelled' WHERE id = ?")->execute([$id]);
    }

    // LINE未連携のまま放置され、タイムアウトで自動キャンセルされた契約
    public function markAbandoned(int $id): void
    {
        $this->pdo->prepare("UPDATE subscriptions SET status = 'abandoned' WHERE id = ?")->execute([$id]);
    }

    // カード登録(mypage.phpのpayment_method_updateフロー)後、まだStripe側に契約が存在しないこの家族の
    // trial/trial_expired契約をすべて返す。1家族に複数利用者がいる場合に対応するため配列で返す
    public function findUnattachedForFamily(int $familyAccountId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM subscriptions WHERE family_account_id = ? AND payment_customer_ref IS NULL
             AND status IN ('trial', 'trial_expired') ORDER BY id ASC"
        );
        $stmt->execute([$familyAccountId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // 利用者の現在有効な契約(解約・放置済みでないもの)を1件返す。CancellationServiceの解約操作の起点に使う
    public function findActiveForUser(int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM subscriptions WHERE user_id = ? AND status NOT IN ('cancelled', 'abandoned') ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    // 期間終了時解約(cancel_at_period_end)の予定日時を保存する。statusはWebhook到達まで変えない
    // (それまでは引き続き利用可能なため)
    public function scheduleCancellation(int $id, string $cancelAt): void
    {
        $this->pdo->prepare('UPDATE subscriptions SET cancel_at = ? WHERE id = ?')->execute([$cancelAt, $id]);
    }

    // Stripe側で解約予約が取り消された(customer.subscription.updatedでcancel_at_period_end=falseに
    // 戻った)場合に、マイページ表示との整合性を保つために呼ぶ
    public function clearScheduledCancellation(int $id): void
    {
        $this->pdo->prepare('UPDATE subscriptions SET cancel_at = NULL WHERE id = ?')->execute([$id]);
    }
}
