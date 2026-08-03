<?php
class ConversationInsightRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // regenerate_summaries.phpの自己レビューバッチから、検出した要望・トラブルを1件ずつ記録する。
    // 専用の確認画面は無く、運営者がSQLで直接参照・statusを更新する運用
    public function insert(int $userId, string $insightType, string $content, ?int $throughConversationId): void
    {
        if (!in_array($insightType, ['user_request', 'trouble'], true)) {
            throw new InvalidArgumentException("unknown insight_type: {$insightType}");
        }

        $this->pdo->prepare(
            'INSERT INTO conversation_insights (user_id, insight_type, content, through_conversation_id)
             VALUES (?, ?, ?, ?)'
        )->execute([$userId, $insightType, $content, $throughConversationId]);
    }
}
