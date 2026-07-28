<?php
class RiskEventRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // マイページの「安心レポート」表示用。新しい順に返す
    public function getRecentForUser(int $userId, int $limit = 10): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT re.id, re.risk_level, re.status, re.matched_keywords, re.created_at, rp.pattern_name
             FROM risk_events re
             LEFT JOIN risk_patterns rp ON rp.id = re.risk_pattern_id
             WHERE re.user_id = ?
             ORDER BY re.created_at DESC
             LIMIT ' . max(1, $limit)
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // 家族へのLINE通知を送った直後に呼ぶ
    public function markNotified(int $id): void
    {
        $this->pdo->prepare(
            "UPDATE risk_events SET notified_family = 1, notified_at = NOW(), status = 'notified' WHERE id = ?"
        )->execute([$id]);
    }
}
