<?php
require_once __DIR__ . '/PolicyVersions.php';

class ConsentLogRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // 申込み確認画面での同意取得時に、terms/privacy両方をまとめて記録する。
    // 特定商取引法・個人情報保護法上の証跡のため、user_agentは異常に長い入力を弾くため500文字で切る
    public function recordBoth(int $familyAccountId, string $ipAddress, ?string $userAgent): void
    {
        $userAgent = $userAgent !== null ? mb_substr($userAgent, 0, 500) : null;

        $stmt = $this->pdo->prepare(
            'INSERT INTO consent_logs (family_account_id, consent_type, policy_version, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$familyAccountId, 'terms', PolicyVersions::TERMS_VERSION, $ipAddress, $userAgent]);
        $stmt->execute([$familyAccountId, 'privacy', PolicyVersions::PRIVACY_VERSION, $ipAddress, $userAgent]);
    }
}
