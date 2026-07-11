<?php
class PlanRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function getActivePlans(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, code, name, price_yen, description, stripe_price_id FROM plans WHERE is_active = 1 ORDER BY price_yen ASC'
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, code, name, price_yen, description, stripe_price_id FROM plans WHERE id = ? AND is_active = 1');
        $stmt->execute([$id]);
        $plan = $stmt->fetch(PDO::FETCH_ASSOC);
        return $plan ?: null;
    }

    // stripe_price_idが未設定のプラン(sync_stripe_plans.phpの対象)
    public function getPlansNeedingStripeSync(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, code, name, price_yen, description FROM plans WHERE stripe_price_id IS NULL'
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function setStripeIds(int $planId, string $productId, string $priceId): void
    {
        $this->pdo->prepare(
            'UPDATE plans SET stripe_product_id = ?, stripe_price_id = ? WHERE id = ?'
        )->execute([$productId, $priceId, $planId]);
    }
}
