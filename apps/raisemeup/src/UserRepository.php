<?php
require_once __DIR__ . '/InviteCodeGenerator.php';

class UserRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function findByLineUserId(string $lineUserId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE line_user_id = ?');
        $stmt->execute([$lineUserId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        return $user ?: null;
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        return $user ?: null;
    }

    public function findByInviteCode(string $code): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE invite_code = ?');
        $stmt->execute([$code]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        return $user ?: null;
    }

    /**
     * Web申込フォームから、LINE未連携(status='pending')の状態で利用者本体を作成する。
     * @return array{id: int, invite_code: string}
     */
    public function createPending(array $data): array
    {
        $inviteCode = InviteCodeGenerator::generate($this->pdo, 'users');
        $this->pdo->prepare(
            "INSERT INTO users (invite_code, full_name, display_name, phone, postal_code, address, birthdate, gender, companion_gender, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')"
        )->execute([
            $inviteCode,
            $data['full_name'] ?: null,
            $data['display_name'] ?: null,
            $data['phone'] ?: null,
            $data['postal_code'] ?: null,
            $data['address'] ?: null,
            $data['birthdate'] ?: null,
            $data['gender'] ?: null,
            $data['companion_gender'],
        ]);

        return ['id' => (int) $this->pdo->lastInsertId(), 'invite_code' => $inviteCode];
    }

    public function setCompanionName(int $id, string $companionName): void
    {
        $this->pdo->prepare('UPDATE users SET companion_name = ? WHERE id = ?')->execute([$companionName, $id]);
    }

    // 申込み時には呼び名を聞かないため、会話の中で本人から呼び名が分かった際にConversationHandlerが呼ぶ
    public function setDisplayName(int $id, string $displayName): void
    {
        $this->pdo->prepare('UPDATE users SET display_name = ? WHERE id = ?')->execute([$displayName, $id]);
    }

    // 初回のみ生成される、AIコンパニオン自身の軽い自己紹介(以降固定して使い回す)を保存する
    public function setCompanionPersona(int $id, array $personaFacts): void
    {
        $this->pdo->prepare('UPDATE users SET companion_persona = ? WHERE id = ?')
            ->execute([json_encode($personaFacts, JSON_UNESCAPED_UNICODE), $id]);
    }

    // マイページからの登録情報編集用。display_name(呼び名)は会話の中で確認して設定する運用のため、
    // ここでは意図的に更新対象に含めない(setDisplayNameは別途ConversationHandlerからのみ呼ばれる)
    public function update(int $id, array $data): void
    {
        $this->pdo->prepare(
            'UPDATE users SET full_name = ?, phone = ?, postal_code = ?, address = ?, birthdate = ?, gender = ? WHERE id = ?'
        )->execute([
            $data['full_name'] ?: null,
            $data['phone'] ?: null,
            $data['postal_code'] ?: null,
            $data['address'] ?: null,
            $data['birthdate'] ?: null,
            $data['gender'] ?: null,
            $id,
        ]);
    }

    // LINEログイン(OAuth)成功時に呼ぶ。コードは使い切りなのでクリアする。
    // 友だち追加は別イベント(followイベント)で確認できて初めて完了とするため、
    // ここではstatusをactiveにはしない(友だち未追加のまま何日も放置されると
    // check_subscriptions.phpの放置判定の対象になる)
    public function linkLineUserId(int $id, string $lineUserId): void
    {
        $this->pdo->prepare(
            'UPDATE users SET line_user_id = ?, invite_code = NULL WHERE id = ?'
        )->execute([$lineUserId, $id]);
    }

    // 友だち追加(またはメッセージ到達=友だち確定)が確認できて初めて呼ぶ。ここで初めて本当の意味でオンボード完了とする
    public function markOnboarded(int $id): void
    {
        $this->pdo->prepare(
            "UPDATE users SET status = 'active', onboarded_at = NOW() WHERE id = ?"
        )->execute([$id]);
    }

    // 会話中に「夜9時以降は話しかけないで」等の申告があった場合に、システムからの声かけ(send_proactive_messages.php)を
    // 控える時間帯を保存する。両方nullを渡すと解除(いつでも声かけしてよい状態に戻す)になる
    public function updateQuietHours(int $id, ?string $start, ?string $end): void
    {
        $this->pdo->prepare(
            'UPDATE users SET quiet_hours_start = ?, quiet_hours_end = ? WHERE id = ?'
        )->execute([$start, $end, $id]);
    }
}
