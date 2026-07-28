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
            "INSERT INTO users (invite_code, display_name, phone, postal_code, address, birthdate, gender, companion_gender, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')"
        )->execute([
            $inviteCode,
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

    // マイページからの登録情報編集用
    public function update(int $id, array $data): void
    {
        $this->pdo->prepare(
            'UPDATE users SET display_name = ?, phone = ?, postal_code = ?, address = ?, birthdate = ?, gender = ? WHERE id = ?'
        )->execute([
            $data['display_name'] ?: null,
            $data['phone'] ?: null,
            $data['postal_code'] ?: null,
            $data['address'] ?: null,
            $data['birthdate'] ?: null,
            $data['gender'] ?: null,
            $id,
        ]);
    }

    // 招待コードでのLINE連携が成功した際に呼ぶ。コードは使い切りなのでクリアし、activeにする。
    public function linkLineUserId(int $id, string $lineUserId): void
    {
        $this->pdo->prepare(
            "UPDATE users SET line_user_id = ?, invite_code = NULL, status = 'active', onboarded_at = NOW() WHERE id = ?"
        )->execute([$lineUserId, $id]);
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
