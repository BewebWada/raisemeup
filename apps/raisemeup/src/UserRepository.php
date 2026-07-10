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
            "INSERT INTO users (invite_code, display_name, phone, address, birthdate, status)
             VALUES (?, ?, ?, ?, ?, 'pending')"
        )->execute([
            $inviteCode,
            $data['display_name'] ?: null,
            $data['phone'] ?: null,
            $data['address'] ?: null,
            $data['birthdate'] ?: null,
        ]);

        return ['id' => (int) $this->pdo->lastInsertId(), 'invite_code' => $inviteCode];
    }

    // 招待コードでのLINE連携が成功した際に呼ぶ。コードは使い切りなのでクリアし、activeにする。
    public function linkLineUserId(int $id, string $lineUserId): void
    {
        $this->pdo->prepare(
            "UPDATE users SET line_user_id = ?, invite_code = NULL, status = 'active', onboarded_at = NOW() WHERE id = ?"
        )->execute([$lineUserId, $id]);
    }
}
