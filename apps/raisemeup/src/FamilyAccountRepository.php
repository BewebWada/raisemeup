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

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM family_accounts WHERE id = ?');
        $stmt->execute([$id]);
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

    public function findByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM family_accounts WHERE email = ?');
        $stmt->execute([$email]);
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

    // 放置(未連携タイムアウト)による自動キャンセル時、emailの一意制約が再申込みを永久に妨げないよう解放する
    public function clearEmail(int $id): void
    {
        $this->pdo->prepare('UPDATE family_accounts SET email = NULL WHERE id = ?')->execute([$id]);
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

    // リスク検知の通知先。LINE連携済み(通知チャネルを受け取れる)の有効な紐づけのみ、通知順位順に返す
    public function getNotifiableForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT fa.id, fa.name, fa.line_user_id
             FROM family_accounts fa
             JOIN user_family_links l ON l.family_account_id = fa.id
             WHERE l.user_id = ? AND l.is_active = 1 AND fa.line_user_id IS NOT NULL
             ORDER BY l.notify_priority ASC'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // この家族アカウントが紐づいている利用者(本人)を返す。1家族→複数利用者はDB上は可能だが
    // 申込フロー・マイページとも未対応のため、実運用では常に0〜1件。複数ある場合は通知優先順位が
    // 一番高い(先頭の)ものを使う想定で呼び出し側は先頭要素だけ見ればよい
    public function getLinkedUsersFor(int $familyAccountId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT u.id, u.display_name, l.relation
             FROM users u
             JOIN user_family_links l ON l.user_id = u.id
             WHERE l.family_account_id = ? AND l.is_active = 1
             ORDER BY l.notify_priority ASC'
        );
        $stmt->execute([$familyAccountId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // マイページからの登録情報編集用
    public function update(int $id, array $data): void
    {
        $this->pdo->prepare(
            'UPDATE family_accounts SET name = ?, email = ?, phone = ? WHERE id = ?'
        )->execute([$data['name'], $data['email'] ?: null, $data['phone'] ?: null, $id]);
    }
}
