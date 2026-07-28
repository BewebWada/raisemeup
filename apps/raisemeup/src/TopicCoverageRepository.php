<?php
class TopicCoverageRepository
{
    // 「自己紹介期間」に幅出しを狙う固定の話題ジャンル。要約(SummaryRepository)とは別に、
    // 会話で実際にどのジャンルをカバーしたかを追跡し、同じ話題への偏りを防ぐために使う
    public const CATEGORIES = [
        'family_friends' => '家族・友人関係',
        'hobby' => '趣味・楽しみ',
        'food' => '好きな食べ物',
        'health' => '健康・体調',
        'career_history' => '昔の仕事・経歴',
        'hometown_childhood' => '出身地・子供の頃',
        'pet' => 'ペット',
        'neighborhood' => 'ご近所付き合い・地域',
        'entertainment' => 'テレビ・音楽など娯楽',
    ];

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // 触れたことのあるジャンルだけを ['hobby' => ['last_touched_at' => '...', 'touch_count' => 3], ...] で返す
    // (未着手のジャンルはキー自体が無い)
    public function getAllForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT topic_category, last_touched_at, touch_count FROM user_topic_touches WHERE user_id = ?'
        );
        $stmt->execute([$userId]);

        $coverage = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $coverage[$row['topic_category']] = [
                'last_touched_at' => $row['last_touched_at'],
                'touch_count' => (int) $row['touch_count'],
            ];
        }
        return $coverage;
    }

    // 今回の会話で触れられたジャンル群をまとめて記録する。不正なキー・重複は無視する
    public function touch(int $userId, array $categories): void
    {
        $validCategories = array_unique(array_intersect($categories, array_keys(self::CATEGORIES)));
        if (empty($validCategories)) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO user_topic_touches (user_id, topic_category, first_touched_at, last_touched_at, touch_count)
             VALUES (?, ?, NOW(), NOW(), 1)
             ON DUPLICATE KEY UPDATE last_touched_at = NOW(), touch_count = touch_count + 1'
        );
        foreach ($validCategories as $category) {
            $stmt->execute([$userId, $category]);
        }
    }
}
