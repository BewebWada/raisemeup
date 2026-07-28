<?php
// ご家族が設定する「継続的に気にかけてほしいテーマ」(family_themes)の管理。
// family_messages(1回きりの伝言)とは別物: こちらは出典を明かさずTAYORI自身の関心事として
// 会話に織り込み続けるためのもの。マイページ・LINE(family_webhook.php)どちらからも追加できる。
class FamilyThemeRepository
{
    // テーマの既定有効期間(日数)。季節物(「水分補給」等)が何ヶ月も残り続けて不自然にならないよう、
    // 一定期間で自然に一覧・会話への注入から外れるようにしている。必要なら家族が再度設定し直せばよい
    public const DEFAULT_EXPIRES_IN_DAYS = 30;

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function add(int $userId, int $familyAccountId, string $theme, int $expiresInDays = self::DEFAULT_EXPIRES_IN_DAYS): int
    {
        $this->pdo->prepare(
            'INSERT INTO family_themes (user_id, family_account_id, theme, expires_at)
             VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? DAY))'
        )->execute([$userId, $familyAccountId, $theme, $expiresInDays]);
        return (int) $this->pdo->lastInsertId();
    }

    // 期限切れ・取り消し済みを除いた、現在有効なテーマ一覧(会話プロンプトへの注入・マイページ表示の両方で使う)
    public function getActiveForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, theme, expires_at FROM family_themes
             WHERE user_id = ? AND status = 'active' AND expires_at > NOW()
             ORDER BY created_at ASC"
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // マイページからの削除用。$familyAccountIdで所有権を確認し、他家族のテーマを消せないようにする
    public function cancel(int $themeId, int $familyAccountId): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE family_themes SET status = 'cancelled', cancelled_at = NOW()
             WHERE id = ? AND family_account_id = ? AND status = 'active'"
        );
        $stmt->execute([$themeId, $familyAccountId]);
        return $stmt->rowCount() > 0;
    }
}
