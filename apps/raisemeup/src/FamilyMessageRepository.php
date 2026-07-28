<?php
// ご家族が「TAYORIサポート」経由で本人へ伝える伝言(family_messages)のキュー管理。
// 実際にTAYORIの会話へ織り込む処理はConversationHandler側、AIへの指示はClaudeClient側で行う。
class FamilyMessageRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function queue(int $userId, int $familyAccountId, string $message): int
    {
        $this->pdo->prepare(
            'INSERT INTO family_messages (user_id, family_account_id, message) VALUES (?, ?, ?)'
        )->execute([$userId, $familyAccountId, $message]);
        return (int) $this->pdo->lastInsertId();
    }

    // 家族が「取り消し」等を送ってきたときに使う。この家族アカウントが送った、未配信の直近1件だけを取り消す
    // (複数の利用者と紐づく家族アカウントでも、送信元を跨いで探すため user_id は絞らない)
    public function cancelLatestPending(int $familyAccountId): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT id FROM family_messages WHERE family_account_id = ? AND status = 'pending'
             ORDER BY created_at DESC LIMIT 1"
        );
        $stmt->execute([$familyAccountId]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            return false;
        }
        $this->pdo->prepare("UPDATE family_messages SET status = 'cancelled', cancelled_at = NOW() WHERE id = ?")
            ->execute([$id]);
        return true;
    }

    // 会話プロンプトに載せる、未配信の伝言一覧(送り主の続柄付き)。古い順に返す
    public function getPendingForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT fm.id, fm.message, l.relation, fa.name AS family_name
             FROM family_messages fm
             JOIN user_family_links l ON l.user_id = fm.user_id AND l.family_account_id = fm.family_account_id
             JOIN family_accounts fa ON fa.id = fm.family_account_id
             WHERE fm.user_id = ? AND fm.status = 'pending'
             ORDER BY fm.created_at ASC"
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function markDelivered(array $ids): void
    {
        if (empty($ids)) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $this->pdo->prepare("UPDATE family_messages SET status = 'delivered', delivered_at = NOW() WHERE id IN ({$placeholders})")
            ->execute($ids);
    }
}
