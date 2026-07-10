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
}
