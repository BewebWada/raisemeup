<?php
// スタンダード以上限定の画像認識(原稿対応モード)で読み取ったテキスト(document_texts)の管理。
// 画像自体は保存せず、抽出後のテキストのみを保持する。ベーシックプランではこのテーブルへの書き込みが
// 発生しないため(ConversationHandler側で分岐)、読み取り系メソッドは常に空配列を返すだけで済む。
class DocumentTextRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function save(int $userId, ?int $conversationId, string $extractedText): int
    {
        $this->pdo->prepare(
            'INSERT INTO document_texts (user_id, source_conversation_id, extracted_text) VALUES (?, ?, ?)'
        )->execute([$userId, $conversationId, $extractedText]);
        return (int) $this->pdo->lastInsertId();
    }

    // マイページの一覧表示用(新しい順)
    public function getActiveForUser(int $userId, int $limit = 20): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, extracted_text, created_at FROM document_texts
             WHERE user_id = ? AND status = 'active'
             ORDER BY created_at DESC LIMIT " . max(1, $limit)
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // 会話プロンプトへの注入用(直近数件を古い順で。「さっき見せてくれた書類」を後の雑談でも参照できるように)
    public function getRecentForPromptContext(int $userId, int $limit = 3): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT extracted_text, created_at FROM document_texts
             WHERE user_id = ? AND status = 'active'
             ORDER BY created_at DESC LIMIT " . max(1, $limit)
        );
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_reverse($rows);
    }

    // 後続のテキストターンで、直近の書類内容への確認・訂正発言が取れた場合に追記する。
    // どの書類への言及かの判定はClaude側に委ねず、単純に「直近1件」を対象にする
    // (書類を見せてもらった直後の雑談で確認・訂正が入る、というのがほぼ唯一の実際のユースケースのため)
    public function appendConfirmationNote(int $userId, string $note): bool
    {
        $idStmt = $this->pdo->prepare(
            "SELECT id FROM document_texts WHERE user_id = ? AND status = 'active' ORDER BY created_at DESC LIMIT 1"
        );
        $idStmt->execute([$userId]);
        $id = $idStmt->fetchColumn();
        if ($id === false) {
            return false;
        }

        $this->pdo->prepare(
            "UPDATE document_texts SET extracted_text = CONCAT(extracted_text, '\n\n[本人による確認・訂正] ', ?) WHERE id = ?"
        )->execute([$note, (int) $id]);
        return true;
    }

    // マイページからの削除用。user_idで所有権を確認し、他利用者の書類を消せないようにする
    public function delete(int $id, int $userId): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE document_texts SET status = 'deleted', deleted_at = NOW()
             WHERE id = ? AND user_id = ? AND status = 'active'"
        );
        $stmt->execute([$id, $userId]);
        return $stmt->rowCount() > 0;
    }
}
