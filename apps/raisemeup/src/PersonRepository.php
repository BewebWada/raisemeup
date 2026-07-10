<?php
class PersonRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * $limitを指定すると、話題によく出る順(mention_count降順)にその件数までしか返さない。
     * リアルタイムの会話プロンプトに載せる分は、人物がどれだけ蓄積されても件数の上限を必ず設けること
     * (relationship要約側は全件を見て生成するので、上限からあふれた人物の情報もそちらには残る)。
     */
    public function getNamesByUserId(int $userId, ?int $limit = null): array
    {
        $sql = 'SELECT canonical_name FROM persons WHERE user_id = ? ORDER BY mention_count DESC';
        $params = [$userId];
        if ($limit !== null) {
            $sql .= ' LIMIT ?';
            $params[] = $limit;
        }

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $i => $value) {
            $stmt->bindValue($i + 1, $value, PDO::PARAM_INT);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    // 検索結果1件を "・名前 / 属性 / メモ" のような1行のテキストに整形する
    public static function formatPersonLine(array $row): string
    {
        $line = "・{$row['canonical_name']}";
        if (!empty($row['attribute_type'])) {
            $line .= " / {$row['attribute_type']}:{$row['attribute_value']}";
        }
        if (!empty($row['notes'])) {
            $line .= " / メモ:{$row['notes']}";
        }
        return $line;
    }

    /**
     * リアルタイムプロンプトの上限(件数)からあふれた人物を「必要なときだけ」探すためのキーワード検索。
     * $terms は複数キーワード(いずれか1つでも一致すればヒット)。単純な部分一致なので、
     * 呼び出し側で類義語・言い換えを含めた複数語を渡すこと。
     */
    public function search(int $userId, array $terms, int $limit = 10): array
    {
        $terms = array_values(array_filter(array_map('trim', $terms), fn($t) => $t !== ''));
        if (empty($terms)) {
            return [];
        }

        $conditions = [];
        $params = [$userId];
        foreach ($terms as $term) {
            $like = '%' . $term . '%';
            $conditions[] = '(p.canonical_name LIKE ? OR p.notes LIKE ?)';
            array_push($params, $like, $like);
        }

        $stmt = $this->pdo->prepare(
            "SELECT p.canonical_name, p.notes, pa.attribute_type, pa.attribute_value
             FROM persons p
             LEFT JOIN person_attributes pa ON pa.person_id = p.id AND pa.is_current = 1
             WHERE p.user_id = ? AND (" . implode(' OR ', $conditions) . ")
             ORDER BY p.mention_count DESC
             LIMIT ?"
        );
        $params[] = $limit;
        foreach ($params as $i => $value) {
            $stmt->bindValue($i + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function upsert(int $userId, array $person, int $conversationId): void
    {
        $name = trim($person['name'] ?? '');
        if ($name === '') {
            return;
        }

        $stmt = $this->pdo->prepare('SELECT id FROM persons WHERE user_id = ? AND canonical_name = ?');
        $stmt->execute([$userId, $name]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $personId = (int) $existing['id'];
            $update = ['mention_count = mention_count + 1', 'last_mentioned_at = NOW()'];
            $params = [];
            if (!empty($person['notes'])) {
                $update[] = 'notes = ?';
                $params[] = $person['notes'];
            }
            $params[] = $personId;
            $this->pdo->prepare('UPDATE persons SET ' . implode(', ', $update) . ' WHERE id = ?')->execute($params);
        } else {
            $this->pdo->prepare(
                'INSERT INTO persons (user_id, canonical_name, first_mentioned_at, last_mentioned_at, mention_count, notes)
                 VALUES (?, ?, NOW(), NOW(), 1, ?)'
            )->execute([$userId, $name, $person['notes'] ?? null]);
            $personId = (int) $this->pdo->lastInsertId();
        }

        if (!empty($person['relation'])) {
            $this->upsertAttribute($personId, 'relation', $person['relation'], $conversationId);
        }
    }

    private function upsertAttribute(int $personId, string $type, string $value, int $conversationId): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, attribute_value FROM person_attributes WHERE person_id = ? AND attribute_type = ? AND is_current = 1'
        );
        $stmt->execute([$personId, $type]);
        $current = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($current && $current['attribute_value'] === $value) {
            return; // 変化なし
        }

        if ($current) {
            $this->pdo->prepare(
                'UPDATE person_attributes SET is_current = 0, valid_to = NOW() WHERE id = ?'
            )->execute([$current['id']]);
        }

        $this->pdo->prepare(
            'INSERT INTO person_attributes (person_id, attribute_type, attribute_value, is_current, valid_from, source_conversation_id)
             VALUES (?, ?, ?, 1, NOW(), ?)'
        )->execute([$personId, $type, $value, $conversationId]);
    }
}
