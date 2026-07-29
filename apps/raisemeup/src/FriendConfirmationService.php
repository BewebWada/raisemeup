<?php
// LINE友だち追加が確認できた際の共通処理。
// OAuthコールバック(isFriendの同期チェック)・followイベントのWebhook・
// 申込完了画面の「次へ」ボタン(能動チェック)の3経路すべてから呼ばれる。
class FriendConfirmationService
{
    public static function confirmUser(PDO $pdo, UserRepository $userRepo, LineClient $lineClient, array $user): void
    {
        $userRepo->markOnboarded((int) $user['id']);
        $companionName = $user['companion_name'] ?: 'たより';
        // この時点ではdisplay_nameは未取得(申込み時には聞かず、会話の中で自然に確認する方針のため)なので、
        // 名前で呼びかけず自己紹介のみ行い、呼び名は会話の中でさりげなく尋ねる
        $greeting = "はじめまして!{$companionName}です。\nこれから、よろしくお願いします。\nよかったら、何てお呼びすればいいか教えてくださいね。\n何でも気軽に話しかけてください。";
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
