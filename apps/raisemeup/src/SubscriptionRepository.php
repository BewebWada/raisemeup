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
