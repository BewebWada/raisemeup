<?php

// 利用規約・プライバシーポリシーの制定日(=バージョン識別子)。terms.php/privacy.phpの
// 本文末尾の表示と、consent_logs.policy_versionへの記録値の単一の情報源とする。
// 規約改定時は本文修正とあわせてここも更新すること
class PolicyVersions
{
    public const TERMS_VERSION = '2026-08-12';
    public const PRIVACY_VERSION = '2026-07-11';

    public static function formatDate(string $version): string
    {
        [$y, $m, $d] = explode('-', $version);
        return sprintf('%d年%d月%d日', (int) $y, (int) $m, (int) $d);
    }
}
