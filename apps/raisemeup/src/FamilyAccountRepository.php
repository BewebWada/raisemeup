<?php
require_once __DIR__ . '/InviteCodeGenerator.php';

class FamilyAccountRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Web申込フォームから契約者(payer)として新規作成する。
     * invite_codeも同時に発行する(家族自身がLINE通知を受け取りたい場合に使う任意のコード)。
     * @return array{id: int, invite_code: string}
     */
    public function create(array $data): array
    {
        $inviteCode = InviteCodeGenerator::generate($this->pdo, 'family_accounts');
        $this->pdo->prepare(
            'INSERT INTO family_accounts (name, email, phone, invite_code, is_billing_contact)
             VALUES (?, ?, ?, ?, 1)'
        )->execute([$data['name'], $data['email'] ?: null, $data['phone'] ?: null, $inviteCode]);

        return ['id' => (int) $this->pdo->lastInsertId(), 'invite_code' => $inviteCode];
    }

    public function findByLineUserId(string $lineUserId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM family_accounts WHERE line_user_id = ?');
        $stmt->execute([$lineUserId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findByInviteCode(string $code): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM family_accounts WHERE invite_code = ?');
        $stmt->execute([$code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    // 招待コードでのLINE連携が成功した際に呼ぶ。コードは使い切りなのでクリアする。
    public function linkLineUserId(int $id, string $lineUserId): void
    {
        $this->pdo->prepare(
            'UPDATE family_accounts SET line_user_id = ?, invite_code = NULL WHERE id = ?'
        )->execute([$lineUserId, $id]);
    }

    public function setStripeCustomerId(int $id, string $stripeCustomerId): void
    {
        $this->pdo->prepare(
            'UPDATE family_accounts SET stripe_customer_id = ? WHERE id = ?'
        )->execute([$stripeCustomerId, $id]);
    }

    public function findByStripeCustomerId(string $stripeCustomerId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM family_accounts WHERE stripe_customer_id = ?');
        $stmt->execute([$stripeCustomerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
