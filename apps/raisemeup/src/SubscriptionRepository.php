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
}
