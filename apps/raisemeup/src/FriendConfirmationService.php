<?php
// LINE友だち追加が確認できた際の共通処理。
// OAuthコールバック(isFriendの同期チェック)・followイベントのWebhook・
// 申込完了画面の「次へ」ボタン(能動チェック)の3経路すべてから呼ばれる。
class FriendConfirmationService
{
    public static function confirmUser(PDO $pdo, UserRepository $userRepo, LineClient $lineClient, array $user): void
    {
        $userRepo->markOnboarded((int) $user['id']);
        $displayName = $user['display_name'] ?: 'あなた';
        $companionName = $user['companion_name'] ?: 'たより';
        $greeting = "{$displayName}さん、はじめまして!{$companionName}です。\nこれから、よろしくお願いします。\n何でも気軽に話しかけてくださいね。";
        if ($lineClient->push($user['line_user_id'], $greeting)) {
            // send_proactive_messages.phpの無連絡検知(conversationsの最終更新から判定)に影響するため、
            // outboundメッセージは通常の会話と同様にここでも記録しておく
            $pdo->prepare(
                'INSERT INTO conversations (user_id, direction, message_type, content) VALUES (?, "outbound", "text", ?)'
            )->execute([(int) $user['id'], $greeting]);
        }
    }

    public static function confirmFamily(FamilyAccountRepository $familyRepo, LineClient $lineClient, array $family): void
    {
        $familyRepo->markFriendConfirmed((int) $family['id']);
        $familyName = $family['name'] ?: 'ご家族';
        $lineClient->push(
            $family['line_user_id'],
            "{$familyName}様、登録が完了しました。\n無料期間の終了案内など、お支払いに関するご連絡をこちらのアカウントからお送りします。"
        );
    }
}
