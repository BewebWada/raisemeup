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
            'SELECT id, code, name, price_yen, description FROM plans WHERE is_active = 1 ORDER BY price_yen ASC'
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, code, name, price_yen, description FROM plans WHERE id = ? AND is_active = 1');
        $stmt->execute([$id]);
        $plan = $stmt->fetch(PDO::FETCH_ASSOC);
        return $plan ?: null;
    }
}
