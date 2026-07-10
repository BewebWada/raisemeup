<?php
class SummaryRepository
{
    public const TYPES = ['schedule', 'relationship', 'preference', 'routine'];

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // ['schedule' => '...', 'relationship' => '...', ...] のように種類ごとの要約本文を返す(無ければキー自体が無い)
    public function getAllForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT summary_type, content FROM user_summaries WHERE user_id = ?');
        $stmt->execute([$userId]);

        $summaries = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $summaries[$row['summary_type']] = $row['content'];
        }
        return $summaries;
    }

    // バッチ処理用。['schedule' => ['content'=>..., 'source_conversation_max_id'=>...], ...] を返す
    public function getRawForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT summary_type, content, source_conversation_max_id FROM user_summaries WHERE user_id = ?'
        );
        $stmt->execute([$userId]);

        $summaries = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $summaries[$row['summary_type']] = [
                'content' => $row['content'],
                'source_conversation_max_id' => $row['source_conversation_max_id'],
            ];
        }
        return $summaries;
    }

    public function upsert(int $userId, string $summaryType, string $content, ?int $sourceConversationMaxId): void
    {
        if (!in_array($summaryType, self::TYPES, true)) {
            throw new InvalidArgumentException("unknown summary_type: {$summaryType}");
        }

        $this->pdo->prepare(
            'INSERT INTO user_summaries (user_id, summary_type, content, source_conversation_max_id)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE content = VALUES(content), source_conversation_max_id = VALUES(source_conversation_max_id)'
        )->execute([$userId, $summaryType, $content, $sourceConversationMaxId]);
    }
}
